<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visual design layer.
 *
 * RC3 deliberately styles existing Builder/Woo structures and mirrors variation
 * choices into visual cards while WooCommerce remains the source of truth.
 */
final class Delicat_Builder_V9_Design {
	public const OPTION = 'delicat_builder_v9_design';

	private static ?array $settings_cache = null;
	private static bool $single_product = false;

	public static function boot(): void {
		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_rc41_reference_defaults' ), 3 );
		}
		add_action( 'wp', array( __CLASS__, 'detect_context' ), 35 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 45 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 60 );

		add_action( 'woocommerce_before_variations_form', array( __CLASS__, 'variation_cards_mount' ), 6 );
		add_action( 'wp_footer', array( __CLASS__, 'sticky_purchase_bar' ), 28 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'buy_now_redirect' ), 20 );
	}

	public static function maybe_migrate_rc41_reference_defaults(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( get_option( 'delicat_builder_v9_rc41_reference_migrated', 0 ) ) {
			return;
		}

		$raw = get_option( self::OPTION, array() );
		if ( is_array( $raw ) ) {
			$legacy_reference = (
				'lovable_reference' === ( $raw['preset'] ?? '' )
				&& 'dark' === ( $raw['homepage_mode'] ?? '' )
				&& '#03030f' === strtolower( (string) ( $raw['homepage_background'] ?? '' ) )
				&& '#f6f6fb' === strtolower( (string) ( $raw['homepage_heading'] ?? '' ) )
				&& '#9495a8' === strtolower( (string) ( $raw['homepage_subtitle'] ?? '' ) )
				&& '#08081e' === strtolower( (string) ( $raw['homepage_view_bg'] ?? '' ) )
			);

			if ( $legacy_reference ) {
				$raw['homepage_mode']       = 'light';
				$raw['homepage_background'] = '#f7f8fc';
				$raw['homepage_heading']    = '#11131f';
				$raw['homepage_subtitle']   = '#697087';
				$raw['homepage_view_bg']    = '#11183f';
				update_option( self::OPTION, $raw, false );
				self::reset_settings_cache();
			}
		}

		update_option( 'delicat_builder_v9_rc41_reference_migrated', 1, false );
	}

	public static function defaults(): array {
		return array(
			'enabled'             => 0,
			'scope'               => 'site',
			'selected_page_id'    => 0,
			'preset'              => 'modern_store',
			'carousel_style'      => 'default',
			'primary_color'       => '#7c3aed',
			'secondary_color'     => '#ec4899',
			'accent_color'        => '#2563eb',
			'surface_color'       => '#ffffff',
			'page_tint'           => '#f3f5ff',
			'homepage_mode'       => 'light',
			'homepage_background' => '#ffffff',
			'homepage_heading'    => '#111827',
			'homepage_subtitle'   => '#667085',
			'homepage_view_bg'    => '#151d48',
			'homepage_view_text'  => '#ffffff',
			'homepage_title_d'    => 32,
			'homepage_title_t'    => 30,
			'homepage_title_m'    => 27,
			'carousel_gap_d'      => 18,
			'carousel_gap_t'      => 16,
			'carousel_gap_m'      => 14,
			'product_name_d'      => 18,
			'product_name_t'      => 17,
			'product_name_m'      => 16,
			'badge_bg'            => '#e9ddff',
			'badge_text'          => '#6540d9',
			'heart_bg'            => '#ffffff',
			'heart_color'         => '#ff4d91',
			'heart_icon'          => 'heart',
			'radius'              => 22,
			'carousel_cta'        => 1,
			'carousel_tag'        => 1,
			'carousel_shell'      => 0,
			'carousel_bubble'     => 0,
			'heart_engine'        => 1,
			'heart_guest'         => 1,
			'carousel_pagination' => 1,
			'variation_cards'     => 1,
			'sticky_purchase_bar' => 1,
			'compact_mobile'      => 1,
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

	public static function reset_settings_cache(): void {
		self::$settings_cache = null;
	}

	public static function enabled(): bool {
		if (
			! Delicat_Builder_V9_Core::is_enabled()
			|| Delicat_Builder_V9_Core::is_safe_mode()
			|| empty( self::settings()['enabled'] )
		) {
			return false;
		}

		$s = self::settings();
		$scope = in_array( $s['scope'] ?? 'site', array( 'site', 'selected_page' ), true ) ? $s['scope'] : 'site';
		if ( 'selected_page' === $scope ) {
			$page_id = absint( $s['selected_page_id'] ?? 0 );
			return $page_id > 0 && function_exists( 'is_page' ) && is_page( $page_id );
		}

		return ! is_admin();
	}

	public static function detect_context(): void {
		self::$single_product = self::enabled() && function_exists( 'is_product' ) && is_product();
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( ! self::enabled() ) {
			return $classes;
		}

		$s = self::settings();
		$classes[] = 'delicat-design-studio';
		$classes[] = 'delicat-design-' . sanitize_html_class( $s['preset'] ?? 'modern_store' );
		if ( ! empty( $s['compact_mobile'] ) ) {
			$classes[] = 'delicat-design-compact-mobile';
		}
		$classes[] = 'delicat-carousel-style-' . sanitize_html_class( $s['carousel_style'] ?? 'default' );
		$classes[] = 'delicat-home-mode-' . sanitize_html_class( in_array( $s['homepage_mode'] ?? 'light', array( 'light', 'dark' ), true ) ? $s['homepage_mode'] : 'light' );
		if ( ! empty( $s['carousel_shell'] ) ) {
			$classes[] = 'delicat-carousel-shell-enabled';
		}
		return array_values( array_unique( $classes ) );
	}

	public static function enqueue_assets(): void {
		if ( ! self::enabled() ) {
			return;
		}

		$managed_homepage = false;
		if (
			function_exists( 'is_singular' )
			&& is_singular( 'page' )
			&& function_exists( 'delicat_builder_v9_is_managed_page' )
		) {
			$managed_homepage = delicat_builder_v9_is_managed_page( get_queried_object_id() );
		}

		// Homepage Studio has its own tiny component styles. Do not load the
		// broad Design Studio stylesheet there: this cuts CSS and prevents late
		// generic selectors from overriding carousel geometry.
		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/design-studio.css';
		if ( ! $managed_homepage && is_file( $css ) ) {
			wp_enqueue_style(
				'delicat-builder-v9-design',
				DELICAT_BUILDER_V9_URL . 'assets/css/design-studio.css',
				array(),
				DELICAT_BUILDER_V9_VERSION
			);

			$s = self::settings();
			$primary = sanitize_hex_color( (string) ( $s['primary_color'] ?? '#7c3aed' ) ) ?: '#7c3aed';
			$secondary = sanitize_hex_color( (string) ( $s['secondary_color'] ?? '#ec4899' ) ) ?: '#ec4899';
			$accent = sanitize_hex_color( (string) ( $s['accent_color'] ?? '#2563eb' ) ) ?: '#2563eb';
			$surface = sanitize_hex_color( (string) ( $s['surface_color'] ?? '#ffffff' ) ) ?: '#ffffff';
			$tint = sanitize_hex_color( (string) ( $s['page_tint'] ?? '#f3f5ff' ) ) ?: '#f3f5ff';
			$radius = min( 32, max( 12, absint( $s['radius'] ?? 22 ) ) );

			wp_add_inline_style(
				'delicat-builder-v9-design',
				sprintf(
					'.delicat-design-studio{--dbv9-design-primary:%1$s;--dbv9-design-secondary:%2$s;--dbv9-design-accent:%3$s;--dbv9-design-surface:%4$s;--dbv9-design-tint:%5$s;--dbv9-design-radius:%6$dpx}',
					$primary,
					$secondary,
					$accent,
					$surface,
					$tint,
					$radius
				)
			);
		}

		if ( self::$single_product && ! empty( self::settings()['variation_cards'] ) ) {
			$js = DELICAT_BUILDER_V9_DIR . 'assets/js/design-product.js';
			if ( is_file( $js ) ) {
				wp_enqueue_script(
					'delicat-builder-v9-design-product',
					DELICAT_BUILDER_V9_URL . 'assets/js/design-product.js',
					array(),
					DELICAT_BUILDER_V9_VERSION,
					array( 'in_footer' => true, 'strategy' => 'defer' )
				);
			}
		}
	}

	public static function variation_cards_mount(): void {
		if (
			! self::$single_product
			|| ! self::enabled()
			|| empty( self::settings()['variation_cards'] )
		) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product_Variable ) {
			return;
		}

		/* RC41.1: do not mount the legacy Design variation mirror when the newer
		 * native Woo single builder is already rendering option cards. Running both
		 * produced two consecutive "Choisissez votre option" headings on mobile.
		 * WooCommerce's original selects remain authoritative in either path. */
		if ( class_exists( 'Delicat_Builder_V9_Woo_UI', false ) ) {
			$single = Delicat_Builder_V9_Woo_UI::effective_single_settings();
			if ( ! empty( $single['single_native_builder'] ) && ! empty( $single['single_native_option_cards'] ) ) {
				return;
			}
		}

		echo '<div class="delicat-design-options" data-delicat-design-options>';
		echo '<div class="delicat-design-options__head">';
		echo '<span class="delicat-design-options__eyebrow">' . esc_html__( 'Options disponibles', 'delicat-builder-v9' ) . '</span>';
		echo '<h2>' . esc_html__( 'Choisissez votre option', 'delicat-builder-v9' ) . '</h2>';
		echo '</div>';
		echo '<div class="delicat-design-options__groups" data-delicat-design-groups></div>';
		echo '</div>';
	}

	public static function sticky_purchase_bar(): void {
		if (
			! self::$single_product
			|| ! self::enabled()
			|| empty( self::settings()['sticky_purchase_bar'] )
		) {
			return;
		}

		global $product;
		if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return;
		}

		?>
		<div class="delicat-design-buybar" data-delicat-design-buybar data-buy-nonce="<?php echo esc_attr( wp_create_nonce( 'delicat_builder_buy_now' ) ); ?>" hidden>
			<div class="delicat-design-buybar__inner">
				<div class="delicat-design-buybar__total">
					<span><?php esc_html_e( 'Total', 'delicat-builder-v9' ); ?></span>
					<strong data-delicat-design-total><?php echo wp_kses_post( $product->get_price_html() ); ?></strong>
				</div>
				<div class="delicat-design-buybar__actions">
					<button type="button" class="delicat-design-buybar__button is-cart" data-delicat-design-cart>
						<?php esc_html_e( 'Ajouter au panier', 'delicat-builder-v9' ); ?>
					</button>
					<button type="button" class="delicat-design-buybar__button is-buy" data-delicat-design-buy>
						<?php esc_html_e( 'Acheter maintenant', 'delicat-builder-v9' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	public static function buy_now_redirect( $url ) {
		if (
			empty( $_POST['delicat_buy_now'] )
			|| '1' !== sanitize_text_field( wp_unslash( $_POST['delicat_buy_now'] ) )
			|| empty( $_POST['delicat_buy_now_nonce'] )
		) {
			return $url;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['delicat_buy_now_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'delicat_builder_buy_now' ) ) {
			return $url;
		}

		return function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $url;
	}

	public static function carousel_enhancements(): array {
		$s = self::settings();
		$heart_icon = sanitize_key( (string) ( $s['heart_icon'] ?? 'heart' ) );
		if ( ! in_array( $heart_icon, array( 'heart', 'heart_outline', 'star', 'bolt' ), true ) ) {
			$heart_icon = 'heart';
		}

		$active = self::enabled();

		return array(
			// CTA visibility is now controlled per carousel (`show_cta`).
			// Keep baseline storefront UI consistent even when Design Studio is
			// scoped to another page.
			'cta'        => $active ? ! empty( $s['carousel_cta'] ) : true,
			'tag'        => $active ? ! empty( $s['carousel_tag'] ) : true,
			'shell'      => $active && ! empty( $s['carousel_shell'] ),
			'bubble'     => $active && ! empty( $s['carousel_bubble'] ),
			'heart'      => $active && ! empty( $s['heart_engine'] ),
			'heartGuest' => $active && ! empty( $s['heart_guest'] ),
			'pagination' => $active ? ! empty( $s['carousel_pagination'] ) : true,
			'style'      => sanitize_key( (string) ( $s['carousel_style'] ?? 'default' ) ),
			'gap_d'      => min( 40, max( 0, absint( $s['carousel_gap_d'] ?? 18 ) ) ),
			'gap_t'      => min( 36, max( 0, absint( $s['carousel_gap_t'] ?? 16 ) ) ),
			'gap_m'      => min( 32, max( 0, absint( $s['carousel_gap_m'] ?? 14 ) ) ),
			'name_d'     => min( 40, max( 10, absint( $s['product_name_d'] ?? 18 ) ) ),
			'name_t'     => min( 36, max( 10, absint( $s['product_name_t'] ?? 17 ) ) ),
			'name_m'     => min( 32, max( 10, absint( $s['product_name_m'] ?? 16 ) ) ),
			'badge_bg'   => sanitize_hex_color( (string) ( $s['badge_bg'] ?? '#e9ddff' ) ) ?: '#e9ddff',
			'badge_text' => sanitize_hex_color( (string) ( $s['badge_text'] ?? '#6540d9' ) ) ?: '#6540d9',
			'heart_bg'   => sanitize_hex_color( (string) ( $s['heart_bg'] ?? '#ffffff' ) ) ?: '#ffffff',
			'heart_color'=> sanitize_hex_color( (string) ( $s['heart_color'] ?? '#ff4d91' ) ) ?: '#ff4d91',
			'heart_icon' => $heart_icon,
		);
	}
}
