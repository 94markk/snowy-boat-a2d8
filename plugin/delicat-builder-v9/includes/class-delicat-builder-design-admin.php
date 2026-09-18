<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Design_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Visual Design Studio', 'delicat-builder-v9' ),
			__( 'Design Studio', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-design',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_design',
			Delicat_Builder_V9_Design::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Design::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$clean = array(
			'enabled'             => empty( $input['enabled'] ) ? 0 : 1,
			'scope'               => in_array( $input['scope'] ?? '', array( 'site', 'selected_page' ), true ) ? $input['scope'] : 'site',
			'selected_page_id'    => absint( $input['selected_page_id'] ?? 0 ),
			'preset'              => in_array( $input['preset'] ?? '', array( 'modern_store', 'clean_luxe', 'gaming_glow', 'neon_luxe', 'neo_glass', 'lovable_reference' ), true ) ? $input['preset'] : 'modern_store',
			'carousel_style'      => in_array( $input['carousel_style'] ?? '', array( 'default', 'neon_luxe', 'neo_glass' ), true ) ? $input['carousel_style'] : 'default',
			'primary_color'       => sanitize_hex_color( (string) ( $input['primary_color'] ?? '#7c3aed' ) ) ?: '#7c3aed',
			'secondary_color'     => sanitize_hex_color( (string) ( $input['secondary_color'] ?? '#ec4899' ) ) ?: '#ec4899',
			'accent_color'        => sanitize_hex_color( (string) ( $input['accent_color'] ?? '#2563eb' ) ) ?: '#2563eb',
			'surface_color'       => sanitize_hex_color( (string) ( $input['surface_color'] ?? '#ffffff' ) ) ?: '#ffffff',
			'page_tint'           => sanitize_hex_color( (string) ( $input['page_tint'] ?? '#f3f5ff' ) ) ?: '#f3f5ff',
			'homepage_mode'       => in_array( $input['homepage_mode'] ?? '', array( 'light', 'dark' ), true ) ? $input['homepage_mode'] : 'light',
			'homepage_background' => sanitize_hex_color( (string) ( $input['homepage_background'] ?? '#ffffff' ) ) ?: '#ffffff',
			'homepage_heading'    => sanitize_hex_color( (string) ( $input['homepage_heading'] ?? '#111827' ) ) ?: '#111827',
			'homepage_subtitle'   => sanitize_hex_color( (string) ( $input['homepage_subtitle'] ?? '#667085' ) ) ?: '#667085',
			'homepage_view_bg'    => sanitize_hex_color( (string) ( $input['homepage_view_bg'] ?? '#151d48' ) ) ?: '#151d48',
			'homepage_view_text'  => sanitize_hex_color( (string) ( $input['homepage_view_text'] ?? '#ffffff' ) ) ?: '#ffffff',
			'homepage_title_d'    => min( 64, max( 18, absint( $input['homepage_title_d'] ?? 32 ) ) ),
			'homepage_title_t'    => min( 56, max( 18, absint( $input['homepage_title_t'] ?? 30 ) ) ),
			'homepage_title_m'    => min( 48, max( 16, absint( $input['homepage_title_m'] ?? 27 ) ) ),
			'carousel_gap_d'      => min( 40, max( 0, absint( $input['carousel_gap_d'] ?? 18 ) ) ),
			'carousel_gap_t'      => min( 36, max( 0, absint( $input['carousel_gap_t'] ?? 16 ) ) ),
			'carousel_gap_m'      => min( 32, max( 0, absint( $input['carousel_gap_m'] ?? 14 ) ) ),
			'product_name_d'      => min( 40, max( 10, absint( $input['product_name_d'] ?? 18 ) ) ),
			'product_name_t'      => min( 36, max( 10, absint( $input['product_name_t'] ?? 17 ) ) ),
			'product_name_m'      => min( 32, max( 10, absint( $input['product_name_m'] ?? 16 ) ) ),
			'badge_bg'            => sanitize_hex_color( (string) ( $input['badge_bg'] ?? '#e9ddff' ) ) ?: '#e9ddff',
			'badge_text'          => sanitize_hex_color( (string) ( $input['badge_text'] ?? '#6540d9' ) ) ?: '#6540d9',
			'heart_bg'            => sanitize_hex_color( (string) ( $input['heart_bg'] ?? '#ffffff' ) ) ?: '#ffffff',
			'heart_color'         => sanitize_hex_color( (string) ( $input['heart_color'] ?? '#ff4d91' ) ) ?: '#ff4d91',
			'heart_icon'          => in_array( $input['heart_icon'] ?? '', array( 'heart', 'heart_outline', 'star', 'bolt' ), true ) ? $input['heart_icon'] : 'heart',
			'radius'              => min( 32, max( 12, absint( $input['radius'] ?? 22 ) ) ),
			'carousel_cta'        => empty( $input['carousel_cta'] ) ? 0 : 1,
			'carousel_tag'        => empty( $input['carousel_tag'] ) ? 0 : 1,
			'carousel_shell'      => empty( $input['carousel_shell'] ) ? 0 : 1,
			'carousel_bubble'     => empty( $input['carousel_bubble'] ) ? 0 : 1,
			'heart_engine'        => empty( $input['heart_engine'] ) ? 0 : 1,
			'heart_guest'         => empty( $input['heart_guest'] ) ? 0 : 1,
			'carousel_pagination' => empty( $input['carousel_pagination'] ) ? 0 : 1,
			'variation_cards'     => empty( $input['variation_cards'] ) ? 0 : 1,
			'sticky_purchase_bar' => empty( $input['sticky_purchase_bar'] ) ? 0 : 1,
			'compact_mobile'      => empty( $input['compact_mobile'] ) ? 0 : 1,
		);

		Delicat_Builder_V9_Design::reset_settings_cache();
		return $clean;
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-design' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_style(
			'delicat-builder-v9-design-preview',
			DELICAT_BUILDER_V9_URL . 'assets/css/design-studio.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Delicat_Builder_V9_Design::settings();
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">RC 36 · DYNAMIC CAROUSEL ENGINES</span>
					<h1><?php esc_html_e( 'Design Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Control carousel card gaps, product-name sizes, dynamic badge/heart engines and light/dark homepage styling while keeping the two approved carousel styles secure and very fast.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_design' ); ?>
				<div class="delicat-admin__grid">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Visual preset', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable Design Studio frontend', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'OFF by default so the RC upgrade cannot unexpectedly restyle your live site.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Frontend scope', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[scope]">
								<option value="selected_page" <?php selected( $s['scope'] ?? 'site', 'selected_page' ); ?>><?php esc_html_e( 'Selected page only', 'delicat-builder-v9' ); ?></option>
								<option value="site" <?php selected( $s['scope'] ?? 'site', 'site' ); ?>><?php esc_html_e( 'Whole frontend', 'delicat-builder-v9' ); ?></option>
							</select>
							<small><?php esc_html_e( 'Use Selected page only while building /test-2/ so the live homepage is not restyled.', 'delicat-builder-v9' ); ?></small>
						</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Selected page ID', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="0" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[selected_page_id]" value="<?php echo esc_attr( (string) ( $s['selected_page_id'] ?? 0 ) ); ?>"></div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Preset', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[preset]">
								<option value="modern_store" <?php selected( $s['preset'], 'modern_store' ); ?>>Delicat Modern Store</option>
								<option value="clean_luxe" <?php selected( $s['preset'], 'clean_luxe' ); ?>>Clean Luxe</option>
								<option value="gaming_glow" <?php selected( $s['preset'], 'gaming_glow' ); ?>>Gaming Glow</option>
								<option value="neon_luxe" <?php selected( $s['preset'], 'neon_luxe' ); ?>>Delicat Neon Luxe</option>
								<option value="neo_glass" <?php selected( $s['preset'], 'neo_glass' ); ?>>Delicat Neo Glass</option>
								<option value="lovable_reference" <?php selected( $s['preset'], 'lovable_reference' ); ?>>Lovable Dark Reference</option>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Carousel surface treatment', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_style]">
								<option value="default" <?php selected( $s['carousel_style'] ?? 'default', 'default' ); ?>><?php esc_html_e( 'Default premium', 'delicat-builder-v9' ); ?></option>
								<option value="neon_luxe" <?php selected( $s['carousel_style'] ?? 'default', 'neon_luxe' ); ?>><?php esc_html_e( 'Neon Luxe', 'delicat-builder-v9' ); ?></option>
								<option value="neo_glass" <?php selected( $s['carousel_style'] ?? 'default', 'neo_glass' ); ?>><?php esc_html_e( 'Neo Glass — performance', 'delicat-builder-v9' ); ?></option>
							</select>
							<small><?php esc_html_e( 'Surface treatment only. Delicat Jeux and Delicat Abonnement remain the two product-card geometries.', 'delicat-builder-v9' ); ?></small>
						</div>

						<div class="delicat-field"><label><strong>Primary</strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[primary_color]" value="<?php echo esc_attr( $s['primary_color'] ); ?>"></div>
						<div class="delicat-field"><label><strong>Secondary</strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[secondary_color]" value="<?php echo esc_attr( $s['secondary_color'] ); ?>"></div>
						<div class="delicat-field"><label><strong>Accent</strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></div>
						<div class="delicat-field"><label><strong>Surface</strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[surface_color]" value="<?php echo esc_attr( $s['surface_color'] ); ?>"></div>
						<div class="delicat-field"><label><strong>Page tint</strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[page_tint]" value="<?php echo esc_attr( $s['page_tint'] ); ?>"></div>
						<div class="delicat-field"><label><strong>Card radius</strong></label><input type="number" min="12" max="32" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[radius]" value="<?php echo esc_attr( (string) $s['radius'] ); ?>"></div>

						<hr>
						<h2><?php esc_html_e( 'Homepage appearance', 'delicat-builder-v9' ); ?></h2>
						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Homepage mode', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_mode]">
								<option value="light" <?php selected( $s['homepage_mode'] ?? 'light', 'light' ); ?>><?php esc_html_e( 'Light', 'delicat-builder-v9' ); ?></option>
								<option value="dark" <?php selected( $s['homepage_mode'] ?? 'light', 'dark' ); ?>><?php esc_html_e( 'Dark', 'delicat-builder-v9' ); ?></option>
							</select>
							<small><?php esc_html_e( 'Light mode uses a white page with dark headings. Product cards keep their premium dark styles.', 'delicat-builder-v9' ); ?></small>
						</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Homepage background', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_background]" value="<?php echo esc_attr( $s['homepage_background'] ?? '#ffffff' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'H1 / section title color', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_heading]" value="<?php echo esc_attr( $s['homepage_heading'] ?? '#111827' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Subtitle color', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_subtitle]" value="<?php echo esc_attr( $s['homepage_subtitle'] ?? '#667085' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Voir tout background', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_view_bg]" value="<?php echo esc_attr( $s['homepage_view_bg'] ?? '#151d48' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Voir tout text', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_view_text]" value="<?php echo esc_attr( $s['homepage_view_text'] ?? '#ffffff' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Title size — desktop', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="18" max="64" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_title_d]" value="<?php echo esc_attr( (string) ( $s['homepage_title_d'] ?? 32 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Title size — tablet', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="18" max="56" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_title_t]" value="<?php echo esc_attr( (string) ( $s['homepage_title_t'] ?? 30 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Title size — mobile', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="16" max="48" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[homepage_title_m]" value="<?php echo esc_attr( (string) ( $s['homepage_title_m'] ?? 27 ) ); ?>"> px</div>

						<hr>
						<h2><?php esc_html_e( 'Carousel layout controls', 'delicat-builder-v9' ); ?></h2>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Card gap — desktop', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="0" max="40" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_gap_d]" value="<?php echo esc_attr( (string) ( $s['carousel_gap_d'] ?? 18 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Card gap — tablet', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="0" max="36" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_gap_t]" value="<?php echo esc_attr( (string) ( $s['carousel_gap_t'] ?? 16 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Card gap — mobile', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="0" max="32" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_gap_m]" value="<?php echo esc_attr( (string) ( $s['carousel_gap_m'] ?? 14 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Product name size — desktop', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="10" max="40" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[product_name_d]" value="<?php echo esc_attr( (string) ( $s['product_name_d'] ?? 18 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Product name size — tablet', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="10" max="36" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[product_name_t]" value="<?php echo esc_attr( (string) ( $s['product_name_t'] ?? 17 ) ); ?>"> px</div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Product name size — mobile', 'delicat-builder-v9' ); ?></strong></label><input type="number" min="10" max="32" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[product_name_m]" value="<?php echo esc_attr( (string) ( $s['product_name_m'] ?? 16 ) ); ?>"> px</div>

						<hr>
						<h2><?php esc_html_e( 'Badge & heart engine', 'delicat-builder-v9' ); ?></h2>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Badge background', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[badge_bg]" value="<?php echo esc_attr( $s['badge_bg'] ?? '#e9ddff' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Badge text color', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[badge_text]" value="<?php echo esc_attr( $s['badge_text'] ?? '#6540d9' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Heart background', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[heart_bg]" value="<?php echo esc_attr( $s['heart_bg'] ?? '#ffffff' ); ?>"></div>
						<div class="delicat-field"><label><strong><?php esc_html_e( 'Heart icon color', 'delicat-builder-v9' ); ?></strong></label><input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[heart_color]" value="<?php echo esc_attr( $s['heart_color'] ?? '#ff4d91' ); ?>"></div>

					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Store components', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_tag]" value="1" <?php checked( ! empty( $s['carousel_tag'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Badge Engine', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Master switch for dynamic server-rendered carousel badges. Each carousel chooses its own badge source.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_cta]" value="1" <?php checked( ! empty( $s['carousel_cta'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Gradient “Acheter” CTA on carousel cards', 'delicat-builder-v9' ); ?></strong></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_shell]" value="1" <?php checked( ! empty( $s['carousel_shell'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Carousel glass shell', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Wraps category chips and carousel cards in a dark premium glass look like the reference style.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[heart_engine]" value="1" <?php checked( ! empty( $s['heart_engine'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Dynamic Heart Engine', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Logged-in customers sync favorites securely to their WordPress account using nonce-protected authenticated requests. No counters or polling.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[heart_guest]" value="1" <?php checked( ! empty( $s['heart_guest'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Guest hearts on this device', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Guests keep their favorites in a small first-party cookie on this device; no guest database write or unauthenticated AJAX endpoint.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[carousel_pagination]" value="1" <?php checked( ! empty( $s['carousel_pagination'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Carousel pagination dots', 'delicat-builder-v9' ); ?></strong></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[variation_cards]" value="1" <?php checked( ! empty( $s['variation_cards'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Modern variation option cards', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Mirrors real Woo variation dropdowns and updates the original selects. WooCommerce remains the validation authority.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[sticky_purchase_bar]" value="1" <?php checked( ! empty( $s['sticky_purchase_bar'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Modern sticky purchase bar', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Uses the existing Woo add-to-cart button underneath rather than creating a second cart API.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Design::OPTION ); ?>[compact_mobile]" value="1" <?php checked( ! empty( $s['compact_mobile'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Compact mobile spacing', 'delicat-builder-v9' ); ?></strong></span>
						</label>

						<div class="delicat-warning" style="margin-top:16px">
							<strong><?php esc_html_e( 'Performance rule', 'delicat-builder-v9' ); ?></strong>
							<p><?php esc_html_e( 'No slider framework, no React/Vue runtime and no separate variation API are added. The visual option layer is small vanilla JavaScript on variable product pages only.', 'delicat-builder-v9' ); ?></p>
						</div>
					</section>
				</div>

				<section class="delicat-admin__card" style="margin-top:20px">
					<h2><?php esc_html_e( 'RC11 performance recommendations', 'delicat-builder-v9' ); ?></h2>
					<?php
					$core = Delicat_Builder_V9_Core::settings();
					$gap_m = absint( $s['carousel_gap_m'] ?? 14 );
					$name_m = absint( $s['product_name_m'] ?? 16 );
					$recommendations = array(
						array(
							'ok'   => ! empty( $core['carousel_progressive'] ),
							'text' => __( 'Keep progressive carousel rendering enabled so lower product cards stay inert until needed.', 'delicat-builder-v9' ),
						),
						array(
							'ok'   => ! empty( $core['low_power_mode'] ),
							'text' => __( 'Keep Low Power Mode enabled for cheaper Android devices, reduced-motion users and constrained hardware.', 'delicat-builder-v9' ),
						),
						array(
							'ok'   => $gap_m >= 8 && $gap_m <= 20,
							'text' => __( 'For mobile stability, keep carousel card gap between 8px and 20px unless your design specifically requires otherwise.', 'delicat-builder-v9' ),
						),
						array(
							'ok'   => $name_m <= 20,
							'text' => __( 'Keep mobile product-name size at 20px or below to reduce clipping and layout shifts.', 'delicat-builder-v9' ),
						),
						array(
							'ok'   => 'site' !== ( $core['navigation_scope'] ?? 'marked' ) || empty( $core['app_navigation'] ),
							'text' => __( 'For production, prefer marked-link App Navigation instead of site-wide interception until checkout/account/payment flows are fully staged.', 'delicat-builder-v9' ),
						),
					);
					?>
					<div class="delicat-status-list">
						<?php foreach ( $recommendations as $item ) : ?>
							<div class="delicat-status">
								<span class="delicat-status__dot <?php echo ! empty( $item['ok'] ) ? 'is-ok' : 'is-warn'; ?>"></span>
								<strong><?php echo ! empty( $item['ok'] ) ? esc_html__( 'Good', 'delicat-builder-v9' ) : esc_html__( 'Recommended', 'delicat-builder-v9' ); ?></strong>
								<span><?php echo esc_html( $item['text'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
					<p style="margin-top:12px"><small><?php esc_html_e( 'This panel is admin-only and adds no frontend CSS, JavaScript, API request or database polling.', 'delicat-builder-v9' ); ?></small></p>
				</section>

				<?php submit_button( __( 'Save Design Studio', 'delicat-builder-v9' ) ); ?>
			</form>
		</div>
		<?php
	}
}
