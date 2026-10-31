<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Shell_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Application Shell', 'delicat-builder-v9' ),
			__( 'Shell Studio', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-shell',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_shell',
			Delicat_Builder_V9_Shell::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Shell::defaults(),
			)
		);
		register_setting(
			'delicat_builder_v9_shell',
			Delicat_Builder_V9_Footer::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_footer' ),
				'default'           => Delicat_Builder_V9_Footer::defaults(),
			)
		);
	}

	public static function sanitize_footer( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$old   = Delicat_Builder_V9_Footer::settings();
		$clean = array(
			'enabled'       => empty( $input['enabled'] ) ? 0 : 1,
			'tagline'       => sanitize_text_field( (string) ( $input['tagline'] ?? '' ) ),
			'whatsapp'      => preg_replace( '/[^0-9]/', '', (string) ( $input['whatsapp'] ?? '' ) ),
			'menu_id'       => absint( $input['menu_id'] ?? 0 ),
			'show_payments' => empty( $input['show_payments'] ) ? 0 : 1,
			'show_trust'    => empty( $input['show_trust'] ) ? 0 : 1,
		);
		if ( '' === $clean['tagline'] ) {
			$clean['tagline'] = Delicat_Builder_V9_Footer::defaults()['tagline'];
		}
		if ( $clean['menu_id'] && ! wp_get_nav_menu_object( $clean['menu_id'] ) ) {
			$clean['menu_id'] = 0;
		}
		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) ) {
			Delicat_Builder_V9_Cache::bump_version();
			do_action( 'litespeed_purge_all' );
		}
		return $clean;
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$old   = Delicat_Builder_V9_Shell::settings();

		$scope = in_array( $input['scope'] ?? 'builder_pages', array( 'builder_pages', 'all_frontend' ), true )
			? $input['scope']
			: 'builder_pages';

		$theme = in_array( $input['theme_default'] ?? 'light', array( 'light', 'dark', 'system' ), true )
			? $input['theme_default']
			: 'light';

		$max_width = absint( $input['max_width'] ?? 1320 );
		if ( ! in_array( $max_width, array( 1200, 1320, 1440 ), true ) ) {
			$max_width = 1320;
		}

		$accent = sanitize_hex_color( (string) ( $input['accent_color'] ?? '#6d5dfc' ) );
		if ( ! $accent ) {
			$accent = '#6d5dfc';
		}

		$search_limit = min( 8, max( 4, absint( $input['search_limit'] ?? 6 ) ) );

		$popular_searches = sanitize_textarea_field( (string) ( $input['popular_searches'] ?? '' ) );
		$popular_lines = preg_split( '/[\r\n,]+/', $popular_searches ) ?: array();
		$popular_lines = array_values( array_filter( array_map( static function( $term ) {
			$term = sanitize_text_field( (string) $term );
			return function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 40 ) : substr( $term, 0, 40 );
		}, $popular_lines ) ) );
		$popular_searches = implode( "\n", array_slice( array_unique( $popular_lines ), 0, 10 ) );

		$mobile_nav_scope = in_array( $input['mobile_nav_scope'] ?? 'all_frontend', array( 'follow_shell', 'all_frontend' ), true )
			? $input['mobile_nav_scope']
			: 'all_frontend';

		$clean = array(
			'enabled'           => empty( $input['enabled'] ) ? 0 : 1,
			'scope'             => $scope,
			'header_enabled'    => empty( $input['header_enabled'] ) ? 0 : 1,
			'logo_id'           => absint( $input['logo_id'] ?? 0 ),
			'menu_id'           => absint( $input['menu_id'] ?? 0 ),
			'sticky'            => empty( $input['sticky'] ) ? 0 : 1,
			'show_search'       => empty( $input['show_search'] ) ? 0 : 1,
			'search_suggestions'=> empty( $input['search_suggestions'] ) ? 0 : 1,
			'search_categories' => empty( $input['search_categories'] ) ? 0 : 1,
			'search_limit'      => $search_limit,
			'popular_searches'  => $popular_searches,
			'show_notifications'=> empty( $input['show_notifications'] ) ? 0 : 1,
			'show_cart'         => empty( $input['show_cart'] ) ? 0 : 1,
			'cart_drawer'       => empty( $input['cart_drawer'] ) ? 0 : 1,
			'show_account'      => empty( $input['show_account'] ) ? 0 : 1,
			'show_theme_toggle' => empty( $input['show_theme_toggle'] ) ? 0 : 1,
			'mobile_bottom_nav' => empty( $input['mobile_bottom_nav'] ) ? 0 : 1,
			'mobile_nav_scope'  => $mobile_nav_scope,
			'theme_default'     => $theme,
			'accent_color'      => $accent,
			'max_width'         => $max_width,
			'announcement_text' => sanitize_text_field( (string) ( $input['announcement_text'] ?? '' ) ),
			'announcement_url'  => esc_url_raw( (string) ( $input['announcement_url'] ?? '' ) ),
			'mobile_menu_label' => sanitize_text_field( (string) ( $input['mobile_menu_label'] ?? __( 'Menu', 'delicat-builder-v9' ) ) ),
		);

		// Invalid attachment/menu IDs are stored as zero instead of arbitrary references.
		if ( $clean['logo_id'] && ! Delicat_Builder_V9_Media::is_image_attachment( $clean['logo_id'] ) ) {
			$clean['logo_id'] = 0;
		}
		if ( $clean['menu_id'] && ! wp_get_nav_menu_object( $clean['menu_id'] ) ) {
			$clean['menu_id'] = 0;
		}

		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}

		return $clean;
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-shell' !== $hook ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_style(
			'delicat-builder-v9-shell-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/shell-admin.css',
			array( 'delicat-builder-v9-admin' ),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_script(
			'delicat-builder-v9-shell-admin',
			DELICAT_BUILDER_V9_URL . 'assets/js/shell-admin.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);
	}

	private static function menu_options( int $selected ): void {
		echo '<option value="0">' . esc_html__( '— None —', 'delicat-builder-v9' ) . '</option>';
		foreach ( wp_get_nav_menus() as $menu ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				absint( $menu->term_id ),
				selected( $selected, (int) $menu->term_id, false ),
				esc_html( $menu->name )
			);
		}
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Delicat_Builder_V9_Shell::settings();
		$footer = Delicat_Builder_V9_Footer::settings();
		$logo = '';
		if ( ! empty( $s['logo_id'] ) && Delicat_Builder_V9_Media::is_image_attachment( absint( $s['logo_id'] ) ) ) {
			$logo = wp_get_attachment_image( absint( $s['logo_id'] ), 'medium', false, array( 'class' => 'delicat-shell-admin__logo-image' ) );
		}
		?>
		<div class="wrap delicat-admin delicat-shell-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">APPLICATION SHELL · RC 39.5 FATAL RECOVERY + SAFE SEARCH</span>
					<h1><?php esc_html_e( 'Shell Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Server-rendered commerce shell with fast navigation, optional read-only live search and mini-cart helpers, while WooCommerce remains authoritative for cart, checkout, orders and payments.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Shell settings saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-shell-admin__notice">
				<strong><?php esc_html_e( 'Safe rollout', 'delicat-builder-v9' ); ?></strong>
				<p><?php esc_html_e( 'Automatic shell injection is OFF by default. Enable it on staging first. The current V9 recovery path keeps cart, checkout and My Account protected and allows the read-only product finder to remain available even while Safe Mode quarantines the riskier application layers.', 'delicat-builder-v9' ); ?></p>
			</div>

			<?php
			$core_enabled = Delicat_Builder_V9_Core::is_enabled();
			$safe_mode = Delicat_Builder_V9_Core::is_safe_mode();
			$front_page_id = absint( get_option( 'page_on_front', 0 ) );
			$front_is_builder = $front_page_id > 0 && Delicat_Builder_V9_Pages::is_enabled_for_page( $front_page_id );
			?>
			<div class="notice <?php echo ( $core_enabled && ! $safe_mode && ! empty( $s['enabled'] ) ) ? 'notice-success' : 'notice-warning'; ?> inline">
				<p><strong><?php esc_html_e( 'Activation check:', 'delicat-builder-v9' ); ?></strong>
				<?php
				if ( ! $core_enabled ) {
					esc_html_e( ' Builder runtime is disabled. Turn it on in Delicat Builder settings.', 'delicat-builder-v9' );
				} elseif ( $safe_mode ) {
					echo wp_kses_post( sprintf( __( ' Safe Mode is ON. V9 keeps the mobile bar and read-only live product search available. Mini-cart, Identity and the full shell remain quarantined. Review <a href="%s">Production Center</a> before disabling Safe Mode.', 'delicat-builder-v9' ), esc_url( admin_url( 'admin.php?page=delicat-builder-v9-production' ) ) ) );
				} elseif ( empty( $s['enabled'] ) ) {
					esc_html_e( ' Automatic shell injection is OFF.', 'delicat-builder-v9' );
				} elseif ( 'builder_pages' === $s['scope'] && ! $front_is_builder ) {
					esc_html_e( ' The application header scope is Builder pages only, but your WordPress front page is not marked as a Builder page. The universal footer and global mobile bar remain independent.', 'delicat-builder-v9' );
				} else {
					esc_html_e( ' Core shell conditions look ready. On phones, the mobile bar is rendered at 820px and below.', 'delicat-builder-v9' );
				}
				?>
				</p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_shell' ); ?>
				<div class="delicat-admin__grid">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Application shell', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable automatic shell injection', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Keep OFF until tested with your active theme. Shortcodes remain available independently.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Automatic scope', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[scope]">
								<option value="builder_pages" <?php selected( $s['scope'], 'builder_pages' ); ?>><?php esc_html_e( 'Delicat Builder pages only — recommended', 'delicat-builder-v9' ); ?></option>
								<option value="all_frontend" <?php selected( $s['scope'], 'all_frontend' ); ?>><?php esc_html_e( 'Most frontend pages (Woo sensitive pages still excluded)', 'delicat-builder-v9' ); ?></option>
							</select>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[header_enabled]" value="1" <?php checked( ! empty( $s['header_enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Header', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Server-rendered application header.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Header menu', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[menu_id]">
								<?php self::menu_options( absint( $s['menu_id'] ) ); ?>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Logo', 'delicat-builder-v9' ); ?></strong></label>
							<div class="delicat-shell-admin__logo-preview" data-shell-logo-preview><?php echo wp_kses_post( $logo ); ?></div>
							<div class="delicat-media-row">
								<input type="number" min="0" data-shell-logo-id name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[logo_id]" value="<?php echo esc_attr( (string) $s['logo_id'] ); ?>">
								<button type="button" class="button" data-shell-logo-choose><?php esc_html_e( 'Choose logo', 'delicat-builder-v9' ); ?></button>
								<button type="button" class="button" data-shell-logo-clear><?php esc_html_e( 'Clear', 'delicat-builder-v9' ); ?></button>
							</div>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[sticky]" value="1" <?php checked( ! empty( $s['sticky'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Sticky header', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Uses CSS sticky positioning, not scroll-heavy JavaScript.', 'delicat-builder-v9' ); ?></small></span>
						</label>
					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Actions & appearance', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[show_search]" value="1" <?php checked( ! empty( $s['show_search'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Product search', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Native GET search remains the fallback. Optional live suggestions use a same-origin, nonce-checked, rate-limited read-only request.', 'delicat-builder-v9' ); ?></small></span>
						</label>


						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[search_suggestions]" value="1" <?php checked( ! empty( $s['search_suggestions'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Live product suggestions', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Debounced read-only WooCommerce product suggestions. No cart/order/payment mutation is exposed.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[search_categories]" value="1" <?php checked( ! empty( $s['search_categories'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Popular search shortcuts', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Shows instant search chips before the customer starts typing. No product query runs until a term is tapped or typed.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Popular searches', 'delicat-builder-v9' ); ?></strong></label>
							<textarea rows="3" maxlength="320" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[popular_searches]" placeholder="Free Fire&#10;Roblox&#10;Netflix"><?php echo esc_textarea( $s['popular_searches'] ?? '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One term per line. These chips render instantly without a database query and start live search when tapped.', 'delicat-builder-v9' ); ?></p>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Live suggestion limit', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[search_limit]">
								<option value="4" <?php selected( (int) $s['search_limit'], 4 ); ?>>4</option>
								<option value="6" <?php selected( (int) $s['search_limit'], 6 ); ?>>6</option>
								<option value="8" <?php selected( (int) $s['search_limit'], 8 ); ?>>8</option>
							</select>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[show_notifications]" value="1" <?php checked( ! empty( $s['show_notifications'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Notifications', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Shown only when Builder V9 owns the notification engine; hidden automatically in coexistence/shadow mode.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[show_cart]" value="1" <?php checked( ! empty( $s['show_cart'] ) ); ?>>
							<span><strong><?php esc_html_e( 'WooCommerce cart', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'URL and initial item count come directly from WooCommerce.', 'delicat-builder-v9' ); ?></small></span>
						</label>


						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[cart_drawer]" value="1" <?php checked( ! empty( $s['cart_drawer'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Fast mini-cart drawer', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Loads the current WooCommerce cart only when opened, keeping cacheable pages lighter and avoiding stale cart HTML.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[show_account]" value="1" <?php checked( ! empty( $s['show_account'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Account', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Uses the WooCommerce My Account permalink when available.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[show_theme_toggle]" value="1" <?php checked( ! empty( $s['show_theme_toggle'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Light/Dark theme toggle', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Preference stays only in a first-party cookie in the visitor browser.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<!-- RC29: the bottom bar is retired; this switch is kept for older exports and has no effect. -->
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[mobile_bottom_nav]" value="1" <?php checked( ! empty( $s['mobile_bottom_nav'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Mobile commerce bar', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Fast CSS-fixed navigation: Accueil, Recherche, Boutique, Panier, Compte. No scroll listener or third-party library.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Mobile commerce bar scope', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[mobile_nav_scope]">
								<option value="all_frontend" <?php selected( $s['mobile_nav_scope'] ?? 'all_frontend', 'all_frontend' ); ?>><?php esc_html_e( 'Most frontend pages — recommended for the app bar', 'delicat-builder-v9' ); ?></option>
								<option value="follow_shell" <?php selected( $s['mobile_nav_scope'] ?? 'all_frontend', 'follow_shell' ); ?>><?php esc_html_e( 'Follow full shell scope', 'delicat-builder-v9' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Most frontend pages lets the bottom bar work globally even when the application header stays limited to Builder pages. The universal footer remains site-wide.', 'delicat-builder-v9' ); ?></p>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Default theme', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[theme_default]">
								<option value="light" <?php selected( $s['theme_default'], 'light' ); ?>><?php esc_html_e( 'Light', 'delicat-builder-v9' ); ?></option>
								<option value="dark" <?php selected( $s['theme_default'], 'dark' ); ?>><?php esc_html_e( 'Dark', 'delicat-builder-v9' ); ?></option>
								<option value="system" <?php selected( $s['theme_default'], 'system' ); ?>><?php esc_html_e( 'System', 'delicat-builder-v9' ); ?></option>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Accent color', 'delicat-builder-v9' ); ?></strong></label>
							<input type="color" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>">
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Content width', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[max_width]">
								<option value="1200" <?php selected( (int) $s['max_width'], 1200 ); ?>>1200px</option>
								<option value="1320" <?php selected( (int) $s['max_width'], 1320 ); ?>>1320px</option>
								<option value="1440" <?php selected( (int) $s['max_width'], 1440 ); ?>>1440px</option>
							</select>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Announcement', 'delicat-builder-v9' ); ?></strong></label>
							<input type="text" maxlength="160" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[announcement_text]" value="<?php echo esc_attr( $s['announcement_text'] ); ?>" placeholder="<?php esc_attr_e( 'Livraison instantanée', 'delicat-builder-v9' ); ?>">
							<input type="url" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[announcement_url]" value="<?php echo esc_attr( $s['announcement_url'] ); ?>" placeholder="https://">
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Mobile menu label', 'delicat-builder-v9' ); ?></strong></label>
							<input type="text" maxlength="40" name="<?php echo esc_attr( Delicat_Builder_V9_Shell::OPTION ); ?>[mobile_menu_label]" value="<?php echo esc_attr( $s['mobile_menu_label'] ); ?>">
						</div>
					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Pied de page universel', 'delicat-builder-v9' ); ?></h2>
						<p><?php esc_html_e( 'Un seul pied de page moderne est utilisé sur toutes les pages. Il s’adapte automatiquement au mobile, au mode sombre et aux barres flottantes.', 'delicat-builder-v9' ); ?></p>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Footer::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $footer['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Activer le pied de page universel', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Remplace les anciens pieds de page du thème, des produits et des modèles natifs.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Message de marque', 'delicat-builder-v9' ); ?></strong></label>
							<textarea rows="3" maxlength="240" name="<?php echo esc_attr( Delicat_Builder_V9_Footer::OPTION ); ?>[tagline]"><?php echo esc_textarea( $footer['tagline'] ); ?></textarea>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Numéro WhatsApp', 'delicat-builder-v9' ); ?></strong></label>
							<input type="text" inputmode="numeric" maxlength="20" name="<?php echo esc_attr( Delicat_Builder_V9_Footer::OPTION ); ?>[whatsapp]" value="<?php echo esc_attr( $footer['whatsapp'] ); ?>" placeholder="50933111283">
							<p class="description"><?php esc_html_e( 'Indicatif du pays inclus, chiffres uniquement.', 'delicat-builder-v9' ); ?></p>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Menu supplémentaire', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Footer::OPTION ); ?>[menu_id]">
								<?php self::menu_options( absint( $footer['menu_id'] ) ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'Facultatif. Les liens identiques aux colonnes natives sont dédupliqués automatiquement.', 'delicat-builder-v9' ); ?></p>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Footer::OPTION ); ?>[show_trust]" value="1" <?php checked( ! empty( $footer['show_trust'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Afficher les engagements', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Livraison, sécurité et assistance.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Footer::OPTION ); ?>[show_payments]" value="1" <?php checked( ! empty( $footer['show_payments'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Afficher les moyens de paiement', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'MonCash, NatCash et Delicat Wallet.', 'delicat-builder-v9' ); ?></small></span>
						</label>
					</section>
				</div>

				<?php submit_button( __( 'Save Shell Studio', 'delicat-builder-v9' ) ); ?>
			</form>

			<div class="delicat-shell-admin__shortcodes">
				<strong><?php esc_html_e( 'Manual header integration', 'delicat-builder-v9' ); ?></strong>
				<code>[delicat_app_header]</code>
				<p><?php esc_html_e( 'Use this with a blank/full-width template when you want exact header placement. The universal footer is always placed automatically at the document boundary.', 'delicat-builder-v9' ); ?></p>
			</div>
		</div>
		<?php
	}
}
