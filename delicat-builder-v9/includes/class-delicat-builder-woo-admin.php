<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Woo_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_delicat_builder_v9_woo_fast_archive', array( __CLASS__, 'fast_archive' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'WooCommerce UI Engine', 'delicat-builder-v9' ),
			__( 'Woo UI Studio', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-woo',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_woo_ui',
			Delicat_Builder_V9_Woo_UI::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Woo_UI::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$old   = Delicat_Builder_V9_Woo_UI::settings();

		$clean = array(
			'enabled'              => empty( $input['enabled'] ) ? 0 : 1,
			'archive_enabled'      => empty( $input['archive_enabled'] ) ? 0 : 1,
			'archive_fast_mode'    => empty( $input['archive_fast_mode'] ) ? 0 : 1,
			'archive_products_per_page' => min( 24, max( 8, absint( $input['archive_products_per_page'] ?? 12 ) ) ),
			'archive_show_result_count' => empty( $input['archive_show_result_count'] ) ? 0 : 1,
			'archive_show_ordering' => empty( $input['archive_show_ordering'] ) ? 0 : 1,
			'archive_show_rating'  => empty( $input['archive_show_rating'] ) ? 0 : 1,
			'archive_cta_mode'     => in_array( $input['archive_cta_mode'] ?? '', array( 'product', 'woo' ), true ) ? $input['archive_cta_mode'] : 'product',
			'archive_touch_prefetch' => empty( $input['archive_touch_prefetch'] ) ? 0 : 1,
			'single_enabled'       => empty( $input['single_enabled'] ) ? 0 : 1,
			'card_style'           => Delicat_Builder_V9_Woo_UI::sanitize_card_style( $input['card_style'] ?? 'premium' ),
			'grid_desktop'         => Delicat_Builder_V9_Woo_UI::grid_value( $input['grid_desktop'] ?? 4, 2, 6 ),
			'grid_tablet'          => Delicat_Builder_V9_Woo_UI::grid_value( $input['grid_tablet'] ?? 3, 2, 4 ),
			'grid_mobile'          => Delicat_Builder_V9_Woo_UI::grid_value( $input['grid_mobile'] ?? 2, 1, 2 ),
			'image_ratio'          => Delicat_Builder_V9_Woo_UI::sanitize_image_ratio( $input['image_ratio'] ?? 'square' ),
			'show_stock_badge'     => empty( $input['show_stock_badge'] ) ? 0 : 1,
			'sticky_summary'       => empty( $input['sticky_summary'] ) ? 0 : 1,
			'single_image_preload' => empty( $input['single_image_preload'] ) ? 0 : 1,
			'max_width'            => Delicat_Builder_V9_Woo_UI::max_width( $input['max_width'] ?? 1320 ),
			'accent_color'         => sanitize_hex_color( (string) ( $input['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc',
			'compact_mobile'       => empty( $input['compact_mobile'] ) ? 0 : 1,
		);

		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) ) {
			Delicat_Builder_V9_Cache::bump_version();
			Delicat_Builder_V9_Cache::purge_product_archives();
		}

		return $clean;
	}

public static function fast_archive(): void {
	if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
		wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change archive performance settings.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'delicat_builder_v9_woo_fast_archive' );

	Delicat_Builder_V9_Woo_UI::apply_ultra_fast_archive_profile();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'   => 'delicat-builder-v9-woo',
				'status' => 'fast_archive',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-woo' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	private static function environment(): array {
		$woo = class_exists( 'WooCommerce' );
		$theme = wp_get_theme();

		return array(
			array(
				'label' => 'WooCommerce',
				'ok'    => $woo,
				'text'  => $woo && defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Not detected', 'delicat-builder-v9' ),
			),
			array(
				'label' => 'Theme',
				'ok'    => true,
				'text'  => $theme->get( 'Name' ) ?: __( 'Unknown', 'delicat-builder-v9' ),
			),
			array(
				'label' => 'Theme type',
				'ok'    => true,
				'text'  => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme()
					? __( 'Block theme', 'delicat-builder-v9' )
					: __( 'Classic/hybrid theme', 'delicat-builder-v9' ),
			),
			array(
				'label' => 'Woo theme support',
				'ok'    => current_theme_supports( 'woocommerce' ),
				'text'  => current_theme_supports( 'woocommerce' )
					? __( 'Declared', 'delicat-builder-v9' )
					: __( 'Not declared by theme', 'delicat-builder-v9' ),
			),
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Delicat_Builder_V9_Woo_UI::settings();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		?>
		<div class="wrap delicat-admin">
			<?php if ( 'fast_archive' === $status ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ultra Fast Product Archive profile applied and archive caches purged.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">WOOCOMMERCE UI ENGINE · STAGED NATIVE BUILDERS RC 39.7</span>
					<h1><?php esc_html_e( 'Woo UI Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Modern product archives and single-product presentation while WooCommerce remains responsible for products, variations, cart actions and checkout.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Woo UI settings saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-warning" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
				<strong><?php esc_html_e( 'Native Woo builders', 'delicat-builder-v9' ); ?></strong>
				<p style="flex:1 1 420px;margin:0"><?php esc_html_e( 'Use the V9 visual builders for dynamic single products and product archives. They style native WooCommerce output; they do not replace its commerce engine.', 'delicat-builder-v9' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-single-products' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Single Product Builder', 'delicat-builder-v9' ); ?></a>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Product Archive Builder', 'delicat-builder-v9' ); ?></a>
			</div>

			<div class="delicat-warning">
				<strong><?php esc_html_e( 'Compatibility-first Woo UI', 'delicat-builder-v9' ); ?></strong>
				<p><?php esc_html_e( 'The engine is OFF by default. It does not replace WooCommerce cart, checkout, My Account or variation/add-to-cart forms. Build as a draft, preview privately on real WooCommerce data, then publish as default only after testing.', 'delicat-builder-v9' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_woo_ui' ); ?>

				<div class="delicat-admin__grid">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Engine', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable Woo UI Engine', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Master switch for Woo product/archive presentation.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_enabled]" value="1" <?php checked( ! empty( $s['archive_enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Product archives/categories', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Modern responsive product grid using existing WooCommerce loop markup.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_fast_mode]" value="1" <?php checked( ! empty( $s['archive_fast_mode'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Ultra Fast Archive mode', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Smaller first response, optimized image priority, stable cards and faster product-click warm-up.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Products per archive page', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="8" max="24" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_products_per_page]" value="<?php echo esc_attr( (string) ( $s['archive_products_per_page'] ?? 12 ) ); ?>">
							<p class="description"><?php esc_html_e( '12 is recommended for fast first-load HTML.', 'delicat-builder-v9' ); ?></p>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_show_ordering]" value="1" <?php checked( ! empty( $s['archive_show_ordering'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Show sorting', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Keep the WooCommerce order selector.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_show_result_count]" value="1" <?php checked( ! empty( $s['archive_show_result_count'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Show result count', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Optional. Hiding it trims archive markup.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_show_rating]" value="1" <?php checked( ! empty( $s['archive_show_rating'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Show product ratings', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Disable for lighter archive cards.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_touch_prefetch]" value="1" <?php checked( ! empty( $s['archive_touch_prefetch'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Fast tap warm-up', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Starts a low-priority product-page warm-up only when a touch remains stationary; cancels when it becomes a scroll.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Archive CTA behavior', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[archive_cta_mode]">
								<option value="product" <?php selected( $s['archive_cta_mode'] ?? 'product', 'product' ); ?>><?php esc_html_e( 'Open product page — fastest/safest', 'delicat-builder-v9' ); ?></option>
								<option value="woo" <?php selected( $s['archive_cta_mode'] ?? 'product', 'woo' ); ?>><?php esc_html_e( 'WooCommerce default add-to-cart', 'delicat-builder-v9' ); ?></option>
							</select>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[single_enabled]" value="1" <?php checked( ! empty( $s['single_enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Single-product pages', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Modern gallery/summary styling while preserving WooCommerce purchase hooks.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Product card style', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[card_style]">
								<option value="premium" <?php selected( $s['card_style'], 'premium' ); ?>>Premium</option>
								<option value="glass" <?php selected( $s['card_style'], 'glass' ); ?>>Glass</option>
								<option value="minimal" <?php selected( $s['card_style'], 'minimal' ); ?>>Minimal</option>
								<option value="gaming" <?php selected( $s['card_style'], 'gaming' ); ?>>Gaming</option>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Product image ratio', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[image_ratio]">
								<option value="square" <?php selected( $s['image_ratio'], 'square' ); ?>>1:1 Square</option>
								<option value="portrait" <?php selected( $s['image_ratio'], 'portrait' ); ?>>4:5 Portrait</option>
								<option value="landscape" <?php selected( $s['image_ratio'], 'landscape' ); ?>>4:3 Landscape</option>
							</select>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[show_stock_badge]" value="1" <?php checked( ! empty( $s['show_stock_badge'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Archive stock badge', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Server-rendered Disponible/Indisponible indicator.', 'delicat-builder-v9' ); ?></small></span>
						</label>
					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Responsive performance', 'delicat-builder-v9' ); ?></h2>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Desktop columns', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="2" max="6" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[grid_desktop]" value="<?php echo esc_attr( (string) $s['grid_desktop'] ); ?>">
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Tablet columns', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="2" max="4" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[grid_tablet]" value="<?php echo esc_attr( (string) $s['grid_tablet'] ); ?>">
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Mobile columns', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="1" max="2" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[grid_mobile]" value="<?php echo esc_attr( (string) $s['grid_mobile'] ); ?>">
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[compact_mobile]" value="1" <?php checked( ! empty( $s['compact_mobile'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Compact mobile cards', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Reduces padding/text overhead on small phones.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[sticky_summary]" value="1" <?php checked( ! empty( $s['sticky_summary'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Sticky desktop product summary', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'CSS-only sticky behavior; automatically disabled on smaller screens.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[single_image_preload]" value="1" <?php checked( ! empty( $s['single_image_preload'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Preload primary single-product image', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Adds a responsive high-priority image hint on product pages.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Content width', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[max_width]">
								<option value="1200" <?php selected( (int) $s['max_width'], 1200 ); ?>>1200px</option>
								<option value="1320" <?php selected( (int) $s['max_width'], 1320 ); ?>>1320px</option>
								<option value="1440" <?php selected( (int) $s['max_width'], 1440 ); ?>>1440px</option>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Accent color', 'delicat-builder-v9' ); ?></strong></label>
							<input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Woo_UI::OPTION ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>">
						</div>
					</section>
				</div>

				<?php submit_button( __( 'Save Woo UI Studio', 'delicat-builder-v9' ) ); ?>
			</form>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Ultra Fast Product Archive', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Recommended starting profile: 12 products, 4/3/2 columns, no ratings/result count, product-page CTA, optimized first image, native full Woo navigation and safe touch-intent warm-up.', 'delicat-builder-v9' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="delicat_builder_v9_woo_fast_archive">
					<?php wp_nonce_field( 'delicat_builder_v9_woo_fast_archive' ); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply Ultra Fast Archive profile + purge cache', 'delicat-builder-v9' ); ?></button>
				</form>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Environment', 'delicat-builder-v9' ); ?></h2>
				<div class="delicat-status-list">
					<?php foreach ( self::environment() as $item ) : ?>
						<div class="delicat-status">
							<span class="delicat-status__dot <?php echo $item['ok'] ? 'is-ok' : 'is-warn'; ?>"></span>
							<strong><?php echo esc_html( $item['label'] ); ?></strong>
							<span><?php echo esc_html( $item['text'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Woo UI uses hooks and CSS around existing WooCommerce markup instead of shipping copied WooCommerce template files, reducing template-version maintenance risk.', 'delicat-builder-v9' ); ?></p>
				<?php if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) : ?>
					<div class="delicat-warning" style="margin-top:14px">
						<strong><?php esc_html_e( 'Block-theme note', 'delicat-builder-v9' ); ?></strong>
						<p><?php esc_html_e( 'Woo UI is optimized first for classic/hybrid WooCommerce PHP loops. Block-theme Product Collection layouts may require the dedicated block compatibility pass planned before release candidate.', 'delicat-builder-v9' ); ?></p>
					</div>
				<?php endif; ?>
			</section>
		</div>
		<?php
	}
}
