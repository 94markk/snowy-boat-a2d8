<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Performance_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_delicat_builder_v9_server_turbo_safe', array( __CLASS__, 'apply_server_turbo_safe' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Performance Studio', 'delicat-builder-v9' ),
			__( 'Performance Studio', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-performance',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_performance',
			Delicat_Builder_V9_Performance::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Performance::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'enabled'                  => empty( $input['enabled'] ) ? 0 : 1,
			'critical_css'             => empty( $input['critical_css'] ) ? 0 : 1,
			'critical_max_bytes'       => min( 20000, max( Delicat_Builder_V9_Performance::MIN_CRITICAL_BYTES, absint( $input['critical_max_bytes'] ?? 10000 ) ) ),
			'high_confidence_preloads' => empty( $input['high_confidence_preloads'] ) ? 0 : 1,
			'predictive_navigation'    => empty( $input['predictive_navigation'] ) ? 0 : 1,
			'intent_delay_ms'          => min( 350, max( 40, absint( $input['intent_delay_ms'] ?? 140 ) ) ),
			'prefetch_budget'          => min( 12, max( 1, absint( $input['prefetch_budget'] ?? 3 ) ) ),
			'prefetch_max_bytes'       => min( 1048576, max( 131072, absint( $input['prefetch_max_bytes'] ?? 262144 ) ) ),
			'network_aware'            => empty( $input['network_aware'] ) ? 0 : 1,
			'instant_product_launch'   => empty( $input['instant_product_launch'] ) ? 0 : 1,
			'instant_touch_delay_ms'   => min( 80, max( 0, absint( $input['instant_touch_delay_ms'] ?? 24 ) ) ),
			'server_rendered_pages'    => empty( $input['server_rendered_pages'] ) ? 0 : 1,
			'server_fragment_cache'    => empty( $input['server_fragment_cache'] ) ? 0 : 1,
			'server_fragment_ttl'      => min( 1800, max( 60, absint( $input['server_fragment_ttl'] ?? 300 ) ) ),
			'server_page_cache_hint'   => empty( $input['server_page_cache_hint'] ) ? 0 : 1,
			'server_shell_mode'        => isset( $input['server_shell_mode'] ) && 'theme' === sanitize_key( (string) $input['server_shell_mode'] ) ? 'theme' : 'v9',
			'islands_mode'             => empty( $input['islands_mode'] ) ? 0 : 1,
			'zero_global_js'           => empty( $input['zero_global_js'] ) ? 0 : 1,
			'query_cache_enabled'      => empty( $input['query_cache_enabled'] ) ? 0 : 1,
			'query_cache_ttl'          => min( 3600, max( 60, absint( $input['query_cache_ttl'] ?? 600 ) ) ),
			'turbo_diagnostics'        => empty( $input['turbo_diagnostics'] ) ? 0 : 1,
		);
	}

	public static function apply_server_turbo_safe(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change performance settings.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_server_turbo_safe' );

		Delicat_Builder_V9_Performance::apply_server_turbo_safe_profile();
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) ) {
			Delicat_Builder_V9_Cache::bump_version();
			do_action( 'litespeed_purge_all' );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-performance', 'server-turbo-applied' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-performance' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	private static function count_builder_pages(): int {
		$query = new WP_Query(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => Delicat_Builder_V9_Pages::META_ENABLED,
				'meta_value'             => '1',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return absint( $query->found_posts );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Delicat_Builder_V9_Performance::settings();
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">PERFORMANCE STUDIO · RC51.34 TURBO ENGINE 2.0</span>
					<h1><?php esc_html_e( 'Performance Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Ultra-light critical CSS, resource-graph hints and conservative user-intent warming with storefront-first budgets. No automatic third-party preconnects and no predictive requests on Cart, Checkout or My Account.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Performance Studio settings saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['server-turbo-applied'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><strong><?php esc_html_e( 'Turbo Engine 2.0 Safe applied.', 'delicat-builder-v9' ); ?></strong> <?php esc_html_e( 'Native PHP rendering, Header Studio document ownership, Redis-aware query cache, islands hydration, zero-global-JS rules, critical CSS and safe LiteSpeed hints are active.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-warning">
				<strong><?php esc_html_e( 'Safe optimization policy', 'delicat-builder-v9' ); ?></strong>
				<p><?php esc_html_e( 'Performance Studio uses conservative defaults. It does not defer essential theme/WooCommerce CSS using media hacks, does not cache personalized commerce pages, and does not preconnect payment or supplier origins.', 'delicat-builder-v9' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_performance' ); ?>
				<div class="delicat-admin__grid">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Critical path', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable Performance Studio', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Safe performance master switch; enabled by default on new installs.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[critical_css]" value="1" <?php checked( ! empty( $s['critical_css'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Allow-listed critical CSS', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Inlines only plugin-owned CSS for the current critical component/context. External styles still load normally as the safe fallback.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Critical CSS byte budget', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="9500" max="20000" step="500" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[critical_max_bytes]" value="<?php echo esc_attr( (string) $s['critical_max_bytes'] ); ?>">
							<small><?php esc_html_e( '7,000 bytes is recommended for the storefront. Sources that would exceed the cap are skipped rather than bloating the head.', 'delicat-builder-v9' ); ?></small>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[high_confidence_preloads]" value="1" <?php checked( ! empty( $s['high_confidence_preloads'] ) ); ?>>
							<span><strong><?php esc_html_e( 'High-confidence script preload', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'At most two same-origin Delicat scripts, only when the resource graph says they are immediately useful. Disabled automatically for Save-Data requests.', 'delicat-builder-v9' ); ?></small></span>
						</label>
					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Predictive navigation', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[predictive_navigation]" value="1" <?php checked( ! empty( $s['predictive_navigation'] ) ); ?>>
							<span><strong><?php esc_html_e( 'User-intent page warming', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Waits for hover/focus intent or touch, then warms only safe same-origin product/menu/builder links. No automatic page crawling.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[network_aware]" value="1" <?php checked( ! empty( $s['network_aware'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Network-aware prediction', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Suppresses prediction for Save-Data, 2G/slow-2G and low-power conditions where supported.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[instant_product_launch]" value="1" <?php checked( ! empty( $s['instant_product_launch'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Instant product launch', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Starts a safe same-origin read-only warm request only after high-confidence Acheter intent. Carousel swipes stay excluded and WooCommerce still owns the destination product request.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Acheter touch intent delay (ms)', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="0" max="80" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[instant_touch_delay_ms]" value="<?php echo esc_attr( (string) ( $s['instant_touch_delay_ms'] ?? 24 ) ); ?>">
							<small><?php esc_html_e( '24 ms is recommended: fast enough to begin the product request before tap release while still allowing a swipe to cancel.', 'delicat-builder-v9' ); ?></small>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Intent delay (ms)', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="40" max="350" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[intent_delay_ms]" value="<?php echo esc_attr( (string) $s['intent_delay_ms'] ); ?>">
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Maximum warmed pages per view', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="1" max="12" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[prefetch_budget]" value="<?php echo esc_attr( (string) $s['prefetch_budget'] ); ?>">
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Maximum HTML response size', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[prefetch_max_bytes]">
								<option value="262144" <?php selected( (int) $s['prefetch_max_bytes'], 262144 ); ?>>256 KB</option>
								<option value="524288" <?php selected( (int) $s['prefetch_max_bytes'], 524288 ); ?>>512 KB</option>
								<option value="1048576" <?php selected( (int) $s['prefetch_max_bytes'], 1048576 ); ?>>1 MB</option>
							</select>
						</div>
					</section>
				</div>


				<div class="delicat-admin__grid" style="margin-top:20px">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Native Server Rendering', 'delicat-builder-v9' ); ?></h2>

						<p><?php esc_html_e( 'Recommended production baseline: native V9 documents, server rendering and anonymous body cache. WooCommerce still owns checkout, account, sessions and orders.', 'delicat-builder-v9' ); ?></p>
						<p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_server_turbo_safe' ), 'delicat_builder_v9_server_turbo_safe' ) ); ?>"><?php esc_html_e( 'Apply Turbo Engine 2.0 Safe', 'delicat-builder-v9' ); ?></a></p>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[server_rendered_pages]" value="1" <?php checked( ! empty( $s['server_rendered_pages'] ) ); ?>>
							<span><strong><?php esc_html_e( 'V9 server-rendered Builder pages', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Bypasses the normal theme page template and renders the Builder layout directly in PHP. Cart, Checkout, Account, previews and protected pages are excluded automatically.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[server_fragment_cache]" value="1" <?php checked( ! empty( $s['server_fragment_cache'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Anonymous server fragment cache', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Caches only the public Builder body. Logged-in users and WooCommerce/session/currency cookies bypass it.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Server fragment TTL (seconds)', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="60" max="1800" step="30" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[server_fragment_ttl]" value="<?php echo esc_attr( (string) ( $s['server_fragment_ttl'] ?? 300 ) ); ?>">
							<small><?php esc_html_e( '300 seconds is recommended. Product/page changes invalidate the cache-version immediately.', 'delicat-builder-v9' ); ?></small>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[server_page_cache_hint]" value="1" <?php checked( ! empty( $s['server_page_cache_hint'] ) ); ?>>
							<span><strong><?php esc_html_e( 'LiteSpeed public-cache hint', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Marks clean anonymous Builder requests as cacheable for compatible LiteSpeed page-cache layers. Private/session requests stay no-cache.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Server shell', 'delicat-builder-v9' ); ?></strong></label>
							<select name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[server_shell_mode]">
								<option value="v9" <?php selected( (string) ( $s['server_shell_mode'] ?? 'v9' ), 'v9' ); ?>><?php esc_html_e( 'Full V9 native shell — recommended', 'delicat-builder-v9' ); ?></option>
								<option value="theme" <?php selected( (string) ( $s['server_shell_mode'] ?? 'v9' ), 'theme' ); ?>><?php esc_html_e( 'Theme compatibility shell — emergency only', 'delicat-builder-v9' ); ?></option>
							</select>
							<small><?php esc_html_e( 'V9 native shell removes the active theme document wrapper and uses Header Studio plus a lightweight native footer.', 'delicat-builder-v9' ); ?></small>
						</div>
					</section>
				</div>


				<div class="delicat-admin__grid" style="margin-top:20px">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Turbo Engine 2.0', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[islands_mode]" value="1" <?php checked( ! empty( $s['islands_mode'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Islands hydration', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Keeps carousel HTML server-rendered, then loads its JavaScript only near the viewport or on first interaction. First paint and SEO do not depend on JavaScript.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[zero_global_js]" value="1" <?php checked( ! empty( $s['zero_global_js'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Zero-global-JS rule', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Prevents the V9 theme runtime from loading on unrelated pages that do not use a V9 page, shortcode, header or footer surface.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[query_cache_enabled]" value="1" <?php checked( ! empty( $s['query_cache_enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Redis-aware query cache', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Uses the WordPress persistent object cache when Redis/Memcached is active, otherwise falls back to transients. Product changes invalidate through the existing V9 cache version.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Query cache TTL (seconds)', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="60" max="3600" step="60" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[query_cache_ttl]" value="<?php echo esc_attr( (string) ( $s['query_cache_ttl'] ?? 600 ) ); ?>">
						</div>

						<label class="delicat-switch">
							<input type="checkbox" name="<?php echo esc_attr( Delicat_Builder_V9_Performance::OPTION ); ?>[turbo_diagnostics]" value="1" <?php checked( ! empty( $s['turbo_diagnostics'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Admin Turbo Diagnostics', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Allows administrators to append ?dbv9_turbo_diag=1 and inspect request time, DB queries, V9 asset weight and cache state. Shoppers never see it.', 'delicat-builder-v9' ); ?></small></span>
						</label>
					</section>
				</div>

				<?php submit_button( __( 'Save Performance Studio', 'delicat-builder-v9' ) ); ?>
			</form>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'RC14 Ultra Fast Homepage', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'For Test 2, use Homepage Studio → Apply Ultra Fast Homepage profile. It enables conservative critical CSS, a 2-page prediction budget, progressive carousels, Low Power Mode, compiled assets, and keeps partial App Navigation disabled for Woo/account/payment safety.', 'delicat-builder-v9' ); ?></p>
				<p><strong><?php esc_html_e( 'Important:', 'delicat-builder-v9' ); ?></strong> <?php esc_html_e( 'RC14 also compacts deferred carousel cards automatically on the managed homepage, so this HTML reduction does not depend on the Performance Studio switch.', 'delicat-builder-v9' ); ?></p>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Resource graph status', 'delicat-builder-v9' ); ?></h2>
				<div class="delicat-status-list">
					<div class="delicat-status">
						<span class="delicat-status__dot is-ok"></span>
						<strong><?php esc_html_e( 'Builder pages', 'delicat-builder-v9' ); ?></strong>
						<span><?php echo esc_html( (string) self::count_builder_pages() ); ?></span>
					</div>

					<div class="delicat-status">
						<span class="delicat-status__dot <?php echo ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ? 'is-ok' : ''; ?>"></span>
						<strong><?php esc_html_e( 'Persistent object cache', 'delicat-builder-v9' ); ?></strong>
						<span><?php echo ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) ? esc_html__( 'Active — Redis/Memcached path', 'delicat-builder-v9' ) : esc_html__( 'Not detected — transient fallback', 'delicat-builder-v9' ); ?></span>
					</div>
					<div class="delicat-status">
						<span class="delicat-status__dot is-ok"></span>
						<strong><?php esc_html_e( 'Critical sources', 'delicat-builder-v9' ); ?></strong>
						<span><?php esc_html_e( 'Plugin allow-list only', 'delicat-builder-v9' ); ?></span>
					</div>
					<div class="delicat-status">
						<span class="delicat-status__dot is-ok"></span>
						<strong><?php esc_html_e( 'Third-party preconnect', 'delicat-builder-v9' ); ?></strong>
						<span><?php esc_html_e( 'None automatic', 'delicat-builder-v9' ); ?></span>
					</div>
					<div class="delicat-status">
						<span class="delicat-status__dot is-ok"></span>
						<strong><?php esc_html_e( 'Sensitive-page prediction', 'delicat-builder-v9' ); ?></strong>
						<span><?php esc_html_e( 'Cart/Checkout/Account blocked', 'delicat-builder-v9' ); ?></span>
					</div>
				</div>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_compile_all' ), 'delicat_builder_v9_compile_all' ) ); ?>"><?php esc_html_e( 'Recompile page resource graphs', 'delicat-builder-v9' ); ?></a>
				
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'dbv9_turbo_diag', '1', home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Open live Turbo Diagnostics', 'delicat-builder-v9' ); ?></a>
				</p>
			</section>
		</div>
		<?php
	}
}
