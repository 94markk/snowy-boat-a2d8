<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_delicat_builder_v9_recover_safe_mode', array( __CLASS__, 'recover_safe_mode' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Delicat Builder V9', 'delicat-builder-v9' ),
			__( 'Delicat Builder', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9',
			array( __CLASS__, 'page' ),
			'dashicons-performance',
			58
		);
	}

	/**
	 * Whether a submenu slug was actually registered on this request.
	 *
	 * Read from the live $submenu global rather than assumed: modules stand
	 * down for legacy ownership or an Integrated Systems toggle, and the hub
	 * must not advertise a page that does not exist.
	 */
	private static function page_registered( string $slug ): bool {
		global $submenu;
		if ( ! is_array( $submenu ) ) {
			return false;
		}
		foreach ( $submenu as $items ) {
			foreach ( (array) $items as $item ) {
				if ( is_array( $item ) && isset( $item[2] ) && $slug === (string) $item[2] ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9',
			'delicat_builder_v9_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$old = Delicat_Builder_V9_Core::settings();
		$input = is_array( $input ) ? $input : array();

		$clean = array(
			'enabled'          => empty( $input['enabled'] ) ? 0 : 1,
			'app_navigation'   => empty( $input['app_navigation'] ) ? 0 : 1,
			'navigation_scope' => in_array( $input['navigation_scope'] ?? 'marked', array( 'marked', 'site' ), true ) ? $input['navigation_scope'] : 'marked',
			'content_selector' => Delicat_Builder_V9_Security::sanitize_selector( (string) ( $input['content_selector'] ?? 'main' ) ),
			'prefetch'         => empty( $input['prefetch'] ) ? 0 : 1,
			'cache_ttl'        => min( 3600, max( 60, absint( $input['cache_ttl'] ?? 600 ) ) ),
			'safe_headers'     => empty( $input['safe_headers'] ) ? 0 : 1,
			'low_power_mode'   => empty( $input['low_power_mode'] ) ? 0 : 1,
			'compiled_assets'  => empty( $input['compiled_assets'] ) ? 0 : 1,
			'hero_preload'      => empty( $input['hero_preload'] ) ? 0 : 1,
			'carousel_progressive' => empty( $input['carousel_progressive'] ) ? 0 : 1,
			'carousel_initial'  => min( 12, max( 3, absint( $input['carousel_initial'] ?? 8 ) ) ),
		);

		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}

		return $clean;
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_delicat-builder-v9' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	public static function recover_safe_mode(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this recovery.', 'delicat-builder-v9' ) );
		}
		check_admin_referer( 'delicat_builder_v9_recover_safe_mode' );
		update_option( 'delicat_builder_v9_safe_mode', 0, false );
		update_option(
			'delicat_builder_v9_safe_mode_meta',
			array(
				'version' => DELICAT_BUILDER_V9_VERSION,
				'cleared' => gmdate( 'c' ),
				'context' => 'admin_recovery',
			),
			false
		);
		delete_option( 'delicat_builder_v9_dependency_gate' );
		wp_safe_redirect( admin_url( 'admin.php?page=delicat-builder-v9&recovery=1' ) );
		exit;
	}

	private static function recovery_page(): void {
		$fatal = get_option( 'delicat_builder_v9_last_bootstrap_fatal', array() );
		$fatal = is_array( $fatal ) ? $fatal : array();
		?>
		<div class="wrap delicat-admin">
			<h1><?php esc_html_e( 'Delicat Builder recovery mode', 'delicat-builder-v9' ); ?></h1>
			<p><?php esc_html_e( 'The storefront remains available while the full Builder admin graph is quarantined.', 'delicat-builder-v9' ); ?></p>
			<?php if ( ! empty( $fatal ) ) : ?>
				<div class="notice notice-error inline"><p>
					<?php
					echo esc_html(
						sprintf(
							'%1$s:%2$d — %3$s',
							(string) ( $fatal['file'] ?? 'unknown' ),
							absint( $fatal['line'] ?? 0 ),
							(string) ( $fatal['message'] ?? ( $fatal['kind'] ?? 'fatal' ) )
						)
					);
					?>
				</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="delicat_builder_v9_recover_safe_mode">
				<?php wp_nonce_field( 'delicat_builder_v9_recover_safe_mode' ); ?>
				<?php submit_button( __( 'Retry full Builder modules', 'delicat-builder-v9' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function status_items(): array {
		$items = array(
			array(
				'label' => 'HTTPS',
				'ok'    => is_ssl(),
				'text'  => is_ssl() ? 'Active' : 'Not detected',
			),
			array(
				'label' => 'WooCommerce',
				'ok'    => class_exists( 'WooCommerce' ),
				'text'  => class_exists( 'WooCommerce' ) ? 'Detected' : 'Not detected',
			),
			array(
				'label' => 'WordPress',
				'ok'    => version_compare( get_bloginfo( 'version' ), '6.4', '>=' ),
				'text'  => get_bloginfo( 'version' ),
			),
			array(
				'label' => 'PHP',
				'ok'    => version_compare( PHP_VERSION, '8.0', '>=' ),
				'text'  => PHP_VERSION,
			),
			array(
				'label' => 'Persistent object cache',
				'ok'    => wp_using_ext_object_cache(),
				'text'  => wp_using_ext_object_cache() ? 'Detected' : 'Optional',
			),
		);

		/*
		 * pro.16: three failure ledgers were written and never read by anything.
		 *
		 * The worst of them is the Pro Kernel boot error. When DBP_Kernel::boot()
		 * throws, the bootstrap catches it on purpose, records this option and
		 * carries on as plain V9 — which silently switches off the instant
		 * navigation engine, the route-scoped asset pipeline and the shared state
		 * layer. The storefront still works, just slowly, and nothing anywhere
		 * told the merchant why. The same was true of a maintenance-module boot
		 * failure and of the bootstrap circuit breaker having deactivated the
		 * plugin. Each row appears only when its ledger is non-empty, so a
		 * healthy install shows exactly what it showed before.
		 */
		$pro_error = get_option( 'delicat_builder_v9_pro_boot_error', array() );
		if ( is_array( $pro_error ) && ! empty( $pro_error ) ) {
			$items[] = array(
				'label' => 'Pro Kernel',
				'ok'    => false,
				'text'  => sprintf(
					'Boot failed %s — %s:%d %s. Instant navigation and route-scoped assets are OFF until this is fixed.',
					(string) ( $pro_error['time'] ?? 'unknown' ),
					(string) ( $pro_error['file'] ?? 'unknown' ),
					absint( $pro_error['line'] ?? 0 ),
					(string) ( $pro_error['message'] ?? '' )
				),
			);
		} elseif ( class_exists( 'DBP_Kernel', false ) && is_callable( array( 'DBP_Kernel', 'on' ) ) ) {
			$items[] = array(
				'label' => 'Pro Kernel',
				'ok'    => DBP_Kernel::on( 'navigation' ),
				'text'  => DBP_Kernel::on( 'navigation' ) ? 'Instant navigation active' : 'Loaded, navigation layer switched off',
			);
		} else {
			$items[] = array(
				'label' => 'Pro Kernel',
				'ok'    => false,
				'text'  => 'Not loaded — the storefront is running without the instant navigation engine.',
			);
		}

		$tripped = absint( get_option( 'delicat_builder_v9_circuit_breaker_tripped', 0 ) );
		if ( $tripped > 0 ) {
			$items[] = array(
				'label' => 'Circuit breaker',
				'ok'    => false,
				'text'  => sprintf(
					'Builder deactivated itself after a fatal on %s. Clear delicat_builder_v9_circuit_breaker_tripped once the cause is fixed.',
					gmdate( 'Y-m-d H:i', $tripped ) . ' UTC'
				),
			);
		}

		$maintenance_error = get_option( 'delicat_builder_v9_maintenance_boot_failure', array() );
		if ( is_array( $maintenance_error ) && ! empty( $maintenance_error ) ) {
			$items[] = array(
				'label' => 'Maintenance module',
				'ok'    => false,
				'text'  => sprintf(
					'Boot failed %s — %s',
					(string) ( $maintenance_error['time'] ?? 'unknown' ),
					(string) ( $maintenance_error['message'] ?? '' )
				),
			);
		}

		return $items;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if (
			Delicat_Builder_V9_Core::is_safe_mode()
			|| ! class_exists( 'Delicat_Builder_V9_Compiler', false )
			|| ! is_callable( array( 'Delicat_Builder_V9_Compiler', 'storage_status' ) )
		) {
			self::recovery_page();
			return;
		}

		$settings = Delicat_Builder_V9_Core::settings();
		$compiler_status = Delicat_Builder_V9_Compiler::storage_status();
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">RC 45 · PREMIUM RUNTIME</span>
					<h1>Delicat Builder V9</h1>
					<p>Release-candidate application runtime for WordPress + WooCommerce. RC 36 preserves official Woo Blocks compatibility and adds WordPress Site Health checks, internal self-tests, manual staging evidence and Stable 9.0.0 eligibility gates while preserving Identity Pro and WooCommerce authority.</p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['purged'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Delicat Builder cache version was purged.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['compiled'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Compiled %d Delicat Builder page(s).', 'delicat-builder-v9' ), absint( $_GET['compiled'] ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cleaned'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Removed %d compiled CSS file(s). Pages will rebuild safely when needed.', 'delicat-builder-v9' ), absint( $_GET['cleaned'] ) ) ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-admin__grid">
				<section class="delicat-admin__card">
					<h2>Runtime settings</h2>
					<form method="post" action="options.php">
						<?php settings_fields( 'delicat_builder_v9' ); ?>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
							<span><strong>Enable Delicat Builder runtime</strong><small>Master switch. Turning this off returns the frontend to normal WordPress rendering.</small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[prefetch]" value="1" <?php checked( ! empty( $settings['prefetch'] ) ); ?>>
							<span><strong>Smart prefetch</strong><small>Prepares marked links on hover/touch without navigating.</small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[app_navigation]" value="1" <?php checked( ! empty( $settings['app_navigation'] ) ); ?>>
							<span><strong>App navigation (Beta)</strong><small>Uses same-origin fetch + View Transitions. Keep disabled until tested on staging.</small></span>
						</label>

						<div class="delicat-field">
							<label for="navigation_scope"><strong>Navigation scope</strong></label>
							<select id="navigation_scope" name="delicat_builder_v9_settings[navigation_scope]">
								<option value="marked" <?php selected( $settings['navigation_scope'], 'marked' ); ?>>Marked links only (recommended)</option>
								<option value="site" <?php selected( $settings['navigation_scope'], 'site' ); ?>>Eligible same-origin links</option>
							</select>
						</div>

						<div class="delicat-field">
							<label for="content_selector"><strong>Content selector</strong></label>
							<input id="content_selector" name="delicat_builder_v9_settings[content_selector]" type="text" value="<?php echo esc_attr( $settings['content_selector'] ); ?>" placeholder="main">
							<small>RC 36 accepts only a simple selector: main, #id, or .class.</small>
						</div>

						<div class="delicat-field">
							<label for="cache_ttl"><strong>Product cache TTL (seconds)</strong></label>
							<input id="cache_ttl" name="delicat_builder_v9_settings[cache_ttl]" type="number" min="60" max="3600" value="<?php echo esc_attr( (string) $settings['cache_ttl'] ); ?>">
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[compiled_assets]" value="1" <?php checked( ! empty( $settings['compiled_assets'] ) ); ?>>
							<span><strong>Per-page compiled CSS</strong><small>Combines only the allow-listed component styles used by each Delicat Builder page. Falls back safely if uploads are not writable.</small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[hero_preload]" value="1" <?php checked( ! empty( $settings['hero_preload'] ) ); ?>>
							<span><strong>Hero LCP preload</strong><small>Preloads only the first visible builder hero image, including an optional mobile source.</small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[carousel_progressive]" value="1" <?php checked( ! empty( $settings['carousel_progressive'] ) ); ?>>
							<span><strong>Progressive carousel DOM</strong><small>Keeps later product cards inert until the carousel approaches the viewport or the user scrolls toward them.</small></span>
						</label>

						<div class="delicat-field">
							<label for="carousel_initial"><strong>Initial carousel cards</strong></label>
							<input id="carousel_initial" name="delicat_builder_v9_settings[carousel_initial]" type="number" min="3" max="12" value="<?php echo esc_attr( (string) ( $settings['carousel_initial'] ?? 8 ) ); ?>">
							<small>8 is recommended. Lower values reduce initial DOM/network work; higher values show more products without materialization.</small>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[low_power_mode]" value="1" <?php checked( ! empty( $settings['low_power_mode'] ) ); ?>>
							<span><strong>Low-power adaptation</strong><small>Reduces non-essential motion on low-memory/low-core devices.</small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="delicat_builder_v9_settings[safe_headers]" value="1" <?php checked( ! empty( $settings['safe_headers'] ) ); ?>>
							<span><strong>Conservative security headers</strong><small>Adds nosniff and strict-origin referrer policy without imposing a CSP that may break payment providers.</small></span>
						</label>

						<?php submit_button( 'Save runtime settings' ); ?>
					</form>
				</section>

				<section class="delicat-admin__card">
					<h2>Environment</h2>
					<div class="delicat-status-list">
						<?php foreach ( self::status_items() as $item ) : ?>
							<div class="delicat-status">
								<span class="delicat-status__dot <?php echo $item['ok'] ? 'is-ok' : 'is-warn'; ?>"></span>
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span><?php echo esc_html( $item['text'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<h3>Lightweight product shortcode</h3>
					<code>[delicat_product_carousel title="Jeux populaires" category="jeux" limit="12"]</code>
					<p class="description">The carousel is server rendered, cached, dependency-free, touch friendly and uses native CSS scroll snapping.</p>

					<h3>RC 36 stable-eligibility architecture</h3>
					<div class="delicat-status">
						<span class="delicat-status__dot <?php echo $compiler_status['ok'] ? 'is-ok' : 'is-warn'; ?>"></span>
						<strong>Compiled CSS storage</strong>
						<span><?php echo esc_html( $compiler_status['text'] ); ?></span>
					</div>
					<p class="description">All previous engines remain intact. Identity Pro stays the authentication/security authority, WooCommerce stays the commerce authority, and Production Center now handles release diagnostics and safe rollback controls.</p>
					<p>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_compile_all' ), 'delicat_builder_v9_compile_all' ) ); ?>">Recompile builder pages</a>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_cleanup_compiled' ), 'delicat_builder_v9_cleanup_compiled' ) ); ?>">Clean compiled CSS</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-shell' ) ); ?>">Open Shell Studio</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-woo' ) ); ?>">Open Woo UI Studio</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-purchase' ) ); ?>">Open Purchase Studio</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-performance' ) ); ?>">Open Performance Studio</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-motion' ) ); ?>">Open Animations & Motion</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-security' ) ); ?>">Open Security Studio</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-production' ) ); ?>">Open Production Center</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-builder-v9-release' ) ); ?>">Open Release Center</a>
						<?php
						/*
						 * RC52: these studios existed but were never linked from
						 * the hub. Each link is emitted only when its submenu was
						 * actually registered on this request — several modules
						 * stand down when a legacy plugin owns their feature or
						 * when they are switched off in Integrated Systems, and a
						 * hardcoded link to a page that was never registered is a
						 * "You do not have sufficient permissions" dead end, which
						 * reads as a worse bug than a missing button.
						 */
						$dbv9_hub = array(
							'delicat-builder-v9-announcement'    => 'Open Announcement Studio',
							'delicat-direct-swatches'            => 'Open Swatch Studio',
							'delicat-builder-v9-homepage'        => 'Open Homepage Studio',
							'delicat-builder-v9-design'          => 'Open Design Studio',
							'delicat-builder-v9-site'            => 'Open Site Studio',
							'delicat-builder-v9-header-studio-8' => 'Open Header Studio v8',
							'delicat-builder-v9-integrated'      => 'Open Integrated Systems',
							'delicat-notifications'              => 'Open Notifications',
							'delicat-live-selling'               => 'Open Live Selling',
							'delicat-builder-v9-maintenance'     => 'Open Maintenance',
						);
						foreach ( $dbv9_hub as $dbv9_slug => $dbv9_label ) {
							if ( ! self::page_registered( $dbv9_slug ) ) {
								continue;
							}
							printf(
								'<a class="button button-primary" href="%s">%s</a> ',
								esc_url( admin_url( 'admin.php?page=' . $dbv9_slug ) ),
								esc_html( $dbv9_label )
							);
						}
						if ( self::page_registered( 'delicat-builder-v9-self-test' ) ) {
							printf(
								'<a class="button" href="%s">%s</a>',
								esc_url( admin_url( 'admin.php?page=delicat-builder-v9-self-test' ) ),
								esc_html__( 'Run Self-Test', 'delicat-builder-v9' )
							);
						}
						?>
					</p>

					<h3>Cache</h3>
					<p>Current cache generation: <strong><?php echo esc_html( (string) Delicat_Builder_V9_Cache::version() ); ?></strong></p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_purge' ), 'delicat_builder_v9_purge' ) ); ?>">Purge Delicat cache</a>

					<div class="delicat-warning">
						<strong>Beta safety rule</strong>
						<p>Do not enable site-wide app navigation directly on production. Test “marked links only” on staging first. Cart, checkout, account, admin, login and logout URLs are excluded automatically.</p>
					</div>
				</section>
			</div>
		</div>
		<?php
	}
}
