<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Purchase_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Purchase Experience', 'delicat-builder-v9' ),
			__( 'Purchase Studio', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-purchase',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_purchase_ui',
			Delicat_Builder_V9_Purchase_UI::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Purchase_UI::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$old   = Delicat_Builder_V9_Purchase_UI::settings();

		$label = sanitize_text_field( (string) ( $input['mobile_dock_label'] ?? '' ) );
		if ( '' === $label ) {
			$label = __( 'Acheter maintenant', 'delicat-builder-v9' );
		}

		$clean = array(
			'enabled'              => empty( $input['enabled'] ) ? 0 : 1,
			'quantity_buttons'     => empty( $input['quantity_buttons'] ) ? 0 : 1,
			'cart_auto_update'     => empty( $input['cart_auto_update'] ) ? 0 : 1,
			'mobile_purchase_dock' => empty( $input['mobile_purchase_dock'] ) ? 0 : 1,
			'notice_toasts'        => empty( $input['notice_toasts'] ) ? 0 : 1,
			'cart_style'           => empty( $input['cart_style'] ) ? 0 : 1,
			'checkout_style'       => empty( $input['checkout_style'] ) ? 0 : 1,
			'native_cart'          => empty( $input['native_cart'] ) ? 0 : 1,
			'native_checkout'      => empty( $input['native_checkout'] ) ? 0 : 1,
			'compact_checkout'     => empty( $input['compact_checkout'] ) ? 0 : 1,
			'accent_color'         => sanitize_hex_color( (string) ( $input['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc',
			'max_width'            => Delicat_Builder_V9_Purchase_UI::max_width( $input['max_width'] ?? 1200 ),
			'mobile_dock_label'    => function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 48 ) : substr( $label, 0, 48 ),
		);

		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}

		return $clean;
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-purchase' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	private static function page_mode( string $type ): string {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return __( 'WooCommerce not detected', 'delicat-builder-v9' );
		}

		$page_id = wc_get_page_id( $type );
		if ( $page_id <= 0 ) {
			return __( 'Page not assigned', 'delicat-builder-v9' );
		}

		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post ) {
			return __( 'Page unavailable', 'delicat-builder-v9' );
		}

		$content = (string) $post->post_content;
		if ( 'cart' === $type && function_exists( 'has_block' ) && has_block( 'woocommerce/cart', $post ) ) {
			return __( 'Cart Block', 'delicat-builder-v9' );
		}
		if ( 'checkout' === $type && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post ) ) {
			return __( 'Checkout Block', 'delicat-builder-v9' );
		}

		$shortcode = 'cart' === $type ? 'woocommerce_cart' : 'woocommerce_checkout';
		if ( has_shortcode( $content, $shortcode ) ) {
			return __( 'Classic shortcode', 'delicat-builder-v9' );
		}

		return __( 'Theme/custom layout', 'delicat-builder-v9' );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Delicat_Builder_V9_Purchase_UI::settings();
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">WOO-NATIVE PURCHASE · RC51.58</span>
					<h1><?php esc_html_e( 'Purchase Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Improve product-to-cart-to-checkout interaction without introducing a second cart API or bypassing WooCommerce validation, sessions, orders or payment gateways.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Purchase Studio settings saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-warning">
				<strong><?php esc_html_e( 'Commerce boundary', 'delicat-builder-v9' ); ?></strong>
				<p><?php esc_html_e( 'Purchase Studio changes presentation only. Its native cart submits the official WooCommerce form and nonces; WooCommerce remains the only authority for sessions, validation, cart mutations, totals, checkout, orders and payment data.', 'delicat-builder-v9' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_purchase_ui' ); ?>

				<div class="delicat-admin__grid">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Interaction', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable Purchase Studio', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Master presentation switch. Turning it off returns cart and checkout rendering to WooCommerce and the active theme.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[quantity_buttons]" value="1" <?php checked( ! empty( $s['quantity_buttons'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Accessible − / + quantity buttons', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Uses WooCommerce quantity-input hooks and updates the original native quantity input.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[cart_auto_update]" value="1" <?php checked( ! empty( $s['cart_auto_update'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Classic cart auto-update', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Optional. After quantity changes, activates the existing WooCommerce Update cart button after a short debounce. No custom API.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[mobile_purchase_dock]" value="1" <?php checked( ! empty( $s['mobile_purchase_dock'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Mobile purchase dock', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Mirrors the original simple/variable product button state and triggers the original WooCommerce form/button only.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Mobile dock button label', 'delicat-builder-v9' ); ?></strong></label>
							<input type="text" maxlength="48" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[mobile_dock_label]" value="<?php echo esc_attr( $s['mobile_dock_label'] ); ?>">
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[notice_toasts]" value="1" <?php checked( ! empty( $s['notice_toasts'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Accessible notice toasts', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Copies only visible WooCommerce notice text into a small aria-live toast; it does not replace Woo notices.', 'delicat-builder-v9' ); ?></small></span>
						</label>
					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Cart & checkout presentation', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[native_cart]" value="1" <?php checked( ! empty( $s['native_cart'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Woo-native cart (RC51.58)', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Classic pages use the swipe-card presentation while preserving Woo form fields, nonces, coupons, shipping, taxes, fees, totals and extension hooks. Cart Blocks keep their Store API logic and receive styling only.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[native_checkout]" value="1" <?php checked( ! empty( $s['native_checkout'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Woo-native checkout shell (RC51.58)', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Digital carts show only email, full name and optional phone on both page render and Woo checkout AJAX; physical carts retain WooCommerce’s full address form. The fixed action delegates to WooCommerce’s real place-order control, and TeraWallet remains the wallet engine.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[cart_style]" value="1" <?php checked( ! empty( $s['cart_style'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Legacy cart styling fallback', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Used only when the Woo-native cart switch above is off.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[checkout_style]" value="1" <?php checked( ! empty( $s['checkout_style'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Legacy checkout styling fallback', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Used only when the Woo-native checkout shell switch above is off.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[compact_checkout]" value="1" <?php checked( ! empty( $s['compact_checkout'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Compact checkout spacing', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Optional smaller vertical spacing on checkout. Keep off first if gateways add custom fields.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Content width', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[max_width]">
								<option value="1080" <?php selected( (int) $s['max_width'], 1080 ); ?>>1080px</option>
								<option value="1200" <?php selected( (int) $s['max_width'], 1200 ); ?>>1200px</option>
								<option value="1320" <?php selected( (int) $s['max_width'], 1320 ); ?>>1320px</option>
								<option value="1440" <?php selected( (int) $s['max_width'], 1440 ); ?>>1440px</option>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Accent color', 'delicat-builder-v9' ); ?></strong></label>
							<input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Purchase_UI::OPTION ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>">
						</div>

						<h3><?php esc_html_e( 'Detected WooCommerce pages', 'delicat-builder-v9' ); ?></h3>
						<div class="delicat-status-list">
							<div class="delicat-status">
								<span class="delicat-status__dot is-ok"></span>
								<strong><?php esc_html_e( 'Cart', 'delicat-builder-v9' ); ?></strong>
								<span><?php echo esc_html( self::page_mode( 'cart' ) ); ?></span>
							</div>
							<div class="delicat-status">
								<span class="delicat-status__dot is-ok"></span>
								<strong><?php esc_html_e( 'Checkout', 'delicat-builder-v9' ); ?></strong>
								<span><?php echo esc_html( self::page_mode( 'checkout' ) ); ?></span>
							</div>
						</div>
					</section>
				</div>

				<?php submit_button( __( 'Save Purchase Studio', 'delicat-builder-v9' ) ); ?>
			</form>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Performance policy', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Purchase Studio adds no polling and no custom cart/checkout network endpoint. Cart, Checkout and My Account must remain dynamic and should stay excluded from page caches. Do not force full-page caching on personalized commerce pages.', 'delicat-builder-v9' ); ?></p>
			</section>
		</div>
		<?php
	}
}
