<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Editor {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_delicat_builder_v9_save_layout', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_delicat_builder_v9_restore_revision', array( __CLASS__, 'restore' ) );
		add_action( 'admin_post_delicat_builder_v9_toggle_page', array( __CLASS__, 'toggle_page' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Page Builder', 'delicat-builder-v9' ),
			__( 'Page Builder', 'delicat-builder-v9' ),
			'edit_pages',
			'delicat-builder-v9-editor',
			array( __CLASS__, 'page' )
		);

		// RC39.11: WooCommerce template builders are first-class Builder screens.
		// They no longer depend on users noticing a small action button inside the
		// normal WordPress page list. Both callbacks still perform their own
		// capability and module-availability checks before rendering.
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Native Product Builder', 'delicat-builder-v9' ),
			__( 'Native Product Builder', 'delicat-builder-v9' ),
			'manage_woocommerce',
			'delicat-builder-v9-native-product',
			array( __CLASS__, 'single_products_page' )
		);



		add_submenu_page(
			'delicat-builder-v9',
			__( 'Menu Builder', 'delicat-builder-v9' ),
			__( 'Menu Builder', 'delicat-builder-v9' ),
			'manage_woocommerce',
			'delicat-builder-v9-menu-builder',
			array( __CLASS__, 'menu_builder_page' )
		);

		add_submenu_page(
			'delicat-builder-v9',
			__( 'Product Archive Builder', 'delicat-builder-v9' ),
			__( 'Product Archives', 'delicat-builder-v9' ),
			'manage_woocommerce',
			'delicat-builder-v9-product-archives',
			array( __CLASS__, 'product_archives_page' )
		);
	}

	public static function single_products_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot edit single-product templates.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'WooCommerce', false ) && ! function_exists( 'WC' ) ) {
			self::render_module_unavailable( 'woocommerce', __( 'WooCommerce is not active. Native Product Builder requires WooCommerce.', 'delicat-builder-v9' ) );
			return;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Native_Product', false ) || ! is_callable( array( 'Delicat_Builder_V9_Native_Product', 'admin_page' ) ) ) {
			self::render_module_unavailable( 'single-builder', __( 'Native Product Builder did not load. Safe Mode kept WordPress running; review Production Center for the guarded module error.', 'delicat-builder-v9' ) );
			return;
		}
		Delicat_Builder_V9_Native_Product::admin_page();
	}


	public static function menu_builder_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot edit the menu builder.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Menu_Builder', false ) || ! is_callable( array( 'Delicat_Builder_V9_Menu_Builder', 'admin_page' ) ) ) {
			self::render_module_unavailable( 'menu-builder', __( 'Menu Builder did not load. Safe Mode kept WordPress running; review Production Center for the guarded module error.', 'delicat-builder-v9' ) );
			return;
		}
		Delicat_Builder_V9_Menu_Builder::admin_page();
	}

	public static function product_archives_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot edit product-archive templates.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'WooCommerce', false ) && ! function_exists( 'WC' ) ) {
			self::render_module_unavailable( 'woocommerce', __( 'WooCommerce is not active. The native Product Archive Builder requires WooCommerce.', 'delicat-builder-v9' ) );
			return;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) || ! is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'admin_page' ) ) ) {
			self::render_module_unavailable( 'archive-builder', __( 'The Product Archive Builder module did not load. Safe Mode kept WordPress running; review Production Center for the guarded module error.', 'delicat-builder-v9' ) );
			return;
		}
		Delicat_Builder_V9_Archive_Builder::admin_page();
	}

	private static function render_module_unavailable( string $module, string $message ): void {
		$production_url = add_query_arg( array( 'page' => 'delicat-builder-v9-production' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap delicat-editor-shell">
			<div class="delicat-editor-topbar">
				<div class="delicat-editor-topbar__identity">
					<a class="delicat-editor-back" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-editor' ), admin_url( 'admin.php' ) ) ); ?>">←</a>
					<div><span class="delicat-editor-kicker"><?php echo esc_html( 'DELICAT BUILDER · ' . DELICAT_BUILDER_V9_VERSION ); ?></span><h1><?php esc_html_e( 'WooCommerce Builder unavailable', 'delicat-builder-v9' ); ?></h1></div>
				</div>
			</div>
			<div class="notice notice-error"><p><strong><?php esc_html_e( 'The requested Builder module is unavailable.', 'delicat-builder-v9' ); ?></strong> <?php echo esc_html( $message ); ?></p></div>
			<div class="delicat-native-woo-lock"><strong><?php esc_html_e( 'Commerce safety preserved', 'delicat-builder-v9' ); ?></strong><span><?php echo esc_html( sprintf( __( 'Module: %s. V9 did not attempt a partial template takeover. WooCommerce/Elementor remains the fallback.', 'delicat-builder-v9' ), sanitize_key( $module ) ) ); ?></span></div>
			<p><a class="button button-primary" href="<?php echo esc_url( $production_url ); ?>"><?php esc_html_e( 'Open Production Center diagnostics', 'delicat-builder-v9' ); ?></a></p>
		</div>
		<?php
	}

	private static function current_page_id(): int {
		return isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
	}

	public static function assets( string $hook ): void {
		$editor_hooks = array(
			'delicat-builder_page_delicat-builder-v9-editor',
			'delicat-builder_page_delicat-builder-v9-native-product',
			'delicat-builder_page_delicat-builder-v9-product-archives',
		);
		if ( ! in_array( $hook, $editor_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_style(
			'delicat-builder-v9-editor',
			DELICAT_BUILDER_V9_URL . 'assets/css/editor.css',
			array( 'delicat-builder-v9-admin' ),
			DELICAT_BUILDER_V9_VERSION
		);

		$page_id = self::current_page_id();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'delicat-builder_page_delicat-builder-v9-native-product' === $hook ) {
			return; // Native Product Builder owns its admin interactions natively; shared CSS is already loaded above.
		} elseif ( 'delicat-builder_page_delicat-builder-v9-product-archives' === $hook ) {
			$view = 'archives';
		}
		if ( ! $page_id ) {
			if ( 'archives' === $view ) {
				$script = 'archive-builder.js';
				$handle = 'delicat-builder-v9-archive-builder';
			} else {
				$script = 'page-picker.js';
				$handle = 'delicat-builder-v9-page-picker';
			}
			wp_enqueue_script(
				$handle,
				DELICAT_BUILDER_V9_URL . 'assets/js/' . $script,
				array(),
				DELICAT_BUILDER_V9_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			return;
		}

		if ( ! current_user_can( 'edit_post', $page_id ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'delicat-builder-v9-editor',
			DELICAT_BUILDER_V9_URL . 'assets/js/editor.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'delicat-builder-v9-editor',
			'window.DelicaBuilderEditor=' . wp_json_encode(
				array(
					'layout'   => Delicat_Builder_V9_Pages::get_layout( $page_id ),
					'types'    => Delicat_Builder_V9_Schema::section_types(),
					'defaults' => array_map( static function ( $type ) { return Delicat_Builder_V9_Schema::default_section( $type ); }, array_keys( Delicat_Builder_V9_Schema::section_types() ) ),
					'max'      => Delicat_Builder_V9_Schema::MAX_SECTIONS,
					'version'  => DELICAT_BUILDER_V9_VERSION,
				)
			) . ';',
			'before'
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You cannot use the Delicat Builder.', 'delicat-builder-v9' ), 403 );
		}

		$page_id = self::current_page_id();
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( 'delicat-builder_page_delicat-builder-v9-native-product' === $hook ) {
			return; // Native Product Builder owns its admin interactions natively; shared CSS is already loaded above.
		} elseif ( 'delicat-builder_page_delicat-builder-v9-product-archives' === $hook ) {
			$view = 'archives';
		}
		if ( ! $page_id ) {
			if ( 'archives' === $view ) {
				self::product_archives_page();
			} elseif ( 'products' === $view ) {
				self::single_products_page();
			} else {
				self::page_picker();
			}
			return;
		}

		$post = get_post( $page_id );
		if ( ! $post instanceof WP_Post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Invalid page.', 'delicat-builder-v9' ), 404 );
		}

		$layout    = Delicat_Builder_V9_Pages::get_layout( $page_id );
		$revisions = Delicat_Builder_V9_Pages::revisions( $page_id );
		?>
		<div class="wrap delicat-editor-shell">
			<div class="delicat-editor-topbar">
				<div class="delicat-editor-topbar__identity">
					<a class="delicat-editor-back" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-editor' ), admin_url( 'admin.php' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Back to Page Builder', 'delicat-builder-v9' ); ?>">←</a>
					<div>
						<span class="delicat-editor-kicker"><?php echo esc_html( 'DELICAT BUILDER · ' . DELICAT_BUILDER_V9_VERSION ); ?></span>
						<h1><?php echo esc_html( get_the_title( $page_id ) ); ?></h1>
					</div>
				</div>
				<div class="delicat-editor-topbar__actions">
					<a class="button" href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview page', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( get_edit_post_link( $page_id, 'raw' ) ); ?>"><?php esc_html_e( 'WordPress page', 'delicat-builder-v9' ); ?></a>
				</div>
			</div>

			<?php
			$manifest = Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );
			$compiled = Delicat_Builder_V9_Compiler::compiled_asset( $page_id, $manifest );
			?>
			<div class="delicat-editor-compile-status">
				<strong><?php esc_html_e( 'Compiled resource graph', 'delicat-builder-v9' ); ?></strong>
				<span><?php echo esc_html( implode( ' · ', array_map( 'sanitize_key', (array) ( $manifest['components'] ?? array() ) ) ) ?: __( 'No components yet', 'delicat-builder-v9' ) ); ?></span>
				<span><?php echo ! empty( $compiled ) ? esc_html( sprintf( __( 'Compiled CSS: %s bytes', 'delicat-builder-v9' ), number_format_i18n( absint( $manifest['css_bytes'] ?? 0 ) ) ) ) : esc_html__( 'Component CSS fallback', 'delicat-builder-v9' ); ?></span>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Builder layout saved securely.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['restored'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Revision restored.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="delicat-builder-form">
				<input type="hidden" name="action" value="delicat_builder_v9_save_layout">
				<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
				<?php wp_nonce_field( 'delicat_builder_v9_save_layout_' . $page_id, 'delicat_builder_nonce' ); ?>
				<textarea name="layout" id="delicat-builder-layout-json" hidden><?php echo esc_textarea( wp_json_encode( $layout ) ); ?></textarea>

				<div class="delicat-editor-grid">
					<aside class="delicat-editor-panel delicat-editor-library">
						<h2><?php esc_html_e( 'Sections', 'delicat-builder-v9' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Only allow-listed components are available. No arbitrary scripts or HTML.', 'delicat-builder-v9' ); ?></p>
						<div id="delicat-section-library"></div>
					</aside>

					<main class="delicat-editor-canvas-wrap">
						<div class="delicat-editor-canvas-head">
							<div><strong><?php esc_html_e( 'Page canvas', 'delicat-builder-v9' ); ?></strong><span id="delicat-section-count"></span></div>
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Save layout', 'delicat-builder-v9' ); ?></button>
						</div>
						<div id="delicat-builder-canvas" class="delicat-editor-canvas" aria-live="polite"></div>
					</main>

					<aside class="delicat-editor-panel delicat-editor-inspector">
						<h2><?php esc_html_e( 'Inspector', 'delicat-builder-v9' ); ?></h2>
						<div id="delicat-builder-inspector"><p class="description"><?php esc_html_e( 'Select a section to edit it.', 'delicat-builder-v9' ); ?></p></div>

						<?php if ( ! empty( $revisions ) ) : ?>
							<hr>
							<h3><?php esc_html_e( 'Recent revisions', 'delicat-builder-v9' ); ?></h3>
							<div class="delicat-revisions">
								<?php foreach ( array_slice( $revisions, 0, 5 ) as $revision ) :
									$user = get_user_by( 'id', absint( $revision['user_id'] ?? 0 ) );
									$url  = wp_nonce_url(
										add_query_arg(
											array(
												'action'      => 'delicat_builder_v9_restore_revision',
												'page_id'     => $page_id,
												'revision_id' => rawurlencode( (string) ( $revision['id'] ?? '' ) ),
											),
											admin_url( 'admin-post.php' )
										),
										'delicat_builder_v9_restore_' . $page_id . '_' . (string) ( $revision['id'] ?? '' )
									);
									?>
									<a class="delicat-revision" href="<?php echo esc_url( $url ); ?>" onclick="return confirm('Restore this revision? Your current layout will be saved as a revision first.');">
										<strong><?php echo esc_html( wp_date( 'M j, H:i', absint( $revision['time'] ?? time() ) ) ); ?></strong>
										<span><?php echo esc_html( $user ? $user->display_name : __( 'Unknown user', 'delicat-builder-v9' ) ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}

	private static function page_picker(): void {
		$pages = get_pages(
			array(
				'sort_column' => 'post_modified',
				'sort_order'  => 'DESC',
				'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
				'number'      => 200,
			)
		);

		$editable = array_values(
			array_filter(
				$pages,
				static function ( $page ) { return $page instanceof WP_Post && current_user_can( 'edit_post', $page->ID ); }
			)
		);

		if ( ! empty( $editable ) ) {
			update_meta_cache( 'post', wp_list_pluck( $editable, 'ID' ) );
		}

		$total   = count( $editable );
		$enabled = 0;
		$drafts  = 0;
		foreach ( $editable as $page ) {
			if ( Delicat_Builder_V9_Pages::is_enabled_for_page( $page->ID ) ) {
				$enabled++;
			}
			if ( 'publish' !== $page->post_status ) {
				$drafts++;
			}
		}

		$homepage_id = absint( get_option( 'page_on_front' ) );
		?>
		<div class="wrap delicat-page-dashboard">
			<section class="delicat-page-dashboard__hero">
				<div class="delicat-page-dashboard__brand">
					<span class="delicat-page-dashboard__logo" aria-hidden="true">D</span>
					<div>
						<span class="delicat-page-dashboard__eyebrow"><?php echo esc_html( 'DELICAT BUILDER · ' . DELICAT_BUILDER_V9_VERSION ); ?></span>
						<h1><?php esc_html_e( 'Page Builder', 'delicat-builder-v9' ); ?></h1>
						<p><?php esc_html_e( 'Build and manage WordPress pages with compiled, allow-listed Delicat components. The normal WordPress page remains the fallback whenever Builder is disabled.', 'delicat-builder-v9' ); ?></p>
					</div>
				</div>
				<div class="delicat-page-dashboard__hero-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>"><?php esc_html_e( '＋ New WordPress page', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-native-product' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Native Product Builder', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Product Archives', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'All WordPress pages', 'delicat-builder-v9' ); ?></a>
				</div>
			</section>

			<?php
			$woo_active = class_exists( 'WooCommerce', false ) || function_exists( 'WC' );
			$single_ready = class_exists( 'Delicat_Builder_V9_Native_Product', false ) && is_callable( array( 'Delicat_Builder_V9_Native_Product', 'admin_page' ) );
			$archive_ready = class_exists( 'Delicat_Builder_V9_Archive_Builder', false ) && is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'admin_page' ) );
			$single_configs = array();
			$single_drafts = array();
			$archive_configs = $archive_ready && is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'configs' ) ) ? Delicat_Builder_V9_Archive_Builder::configs() : array();
			$archive_drafts = $archive_ready && is_callable( array( 'Delicat_Builder_V9_Archive_Builder', 'drafts' ) ) ? Delicat_Builder_V9_Archive_Builder::drafts() : array();
			$single_published = ! empty( Delicat_Builder_V9_Native_Product::settings()['enabled'] ) ? 1 : 0;
			$archive_published = 0;
			foreach ( $archive_configs as $candidate ) { if ( is_array( $candidate ) && ! empty( $candidate['enabled'] ) && ! empty( $candidate['force_native_template'] ) ) { $archive_published++; } }
			?>
			<section class="delicat-native-template-launchpad" aria-label="<?php esc_attr_e( 'WooCommerce template builders', 'delicat-builder-v9' ); ?>">
				<div class="delicat-native-template-launchpad__head">
					<div><span><?php esc_html_e( 'NATIVE WOO BUILDER', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'WooCommerce templates', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Native Product Builder owns the native WooCommerce single-product presentation; Product Archive Builder remains the V9 archive template engine. WooCommerce stays authoritative for products, variations, cart and checkout.', 'delicat-builder-v9' ); ?></p></div>
					<strong class="<?php echo $woo_active ? 'is-ready' : 'is-missing'; ?>"><?php echo $woo_active ? esc_html__( 'WooCommerce connected', 'delicat-builder-v9' ) : esc_html__( 'WooCommerce required', 'delicat-builder-v9' ); ?></strong>
				</div>
				<div class="delicat-archive-picker delicat-native-template-launchpad__grid">
					<article class="delicat-archive-card<?php echo $single_ready ? ' is-enabled' : ''; ?>">
						<div class="delicat-archive-card__icon" aria-hidden="true">◇</div>
						<div class="delicat-archive-card__body">
							<div class="delicat-archive-card__status"><span class="<?php echo ( $woo_active && $single_ready ) ? 'is-on' : 'is-off'; ?>"><i></i><?php echo ( $woo_active && $single_ready ) ? esc_html__( 'Ready', 'delicat-builder-v9' ) : esc_html__( 'Module unavailable', 'delicat-builder-v9' ); ?></span></div>
							<h2><?php esc_html_e( 'Native Product Builder', 'delicat-builder-v9' ); ?></h2>
							<p><?php esc_html_e( 'Server-rendered V9 single-product document with native WooCommerce variations, stock, cart and Product Fields hooks. No Elementor product template.', 'delicat-builder-v9' ); ?></p>
							<div class="delicat-archive-card__meta"><span><?php echo $single_published ? esc_html__( 'Global profile enabled', 'delicat-builder-v9' ) : esc_html__( 'Global profile disabled', 'delicat-builder-v9' ); ?></span><span><?php esc_html_e( 'V7.4 code removed', 'delicat-builder-v9' ); ?></span><span><?php esc_html_e( 'Woo variations preserved', 'delicat-builder-v9' ); ?></span></div>
						</div>
						<div class="delicat-archive-card__actions"><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-native-product' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Native Product Builder', 'delicat-builder-v9' ); ?></a></div>
					</article>

					<article class="delicat-archive-card<?php echo $archive_ready ? ' is-enabled' : ''; ?>">
						<div class="delicat-archive-card__icon" aria-hidden="true">▦</div>
						<div class="delicat-archive-card__body">
							<div class="delicat-archive-card__status"><span class="<?php echo ( $woo_active && $archive_ready ) ? 'is-on' : 'is-off'; ?>"><i></i><?php echo ( $woo_active && $archive_ready ) ? esc_html__( 'Ready', 'delicat-builder-v9' ) : esc_html__( 'Module unavailable', 'delicat-builder-v9' ); ?></span></div>
							<h2><?php esc_html_e( 'Product Archives', 'delicat-builder-v9' ); ?></h2>
							<p><?php esc_html_e( 'Build Shop, product-category, tag and brand archive layouts while WooCommerce keeps its real catalog query, sorting and pagination.', 'delicat-builder-v9' ); ?></p>
							<div class="delicat-archive-card__meta"><span><?php echo esc_html( sprintf( __( '%d published', 'delicat-builder-v9' ), $archive_published ) ); ?></span><span><?php echo esc_html( sprintf( __( '%d drafts', 'delicat-builder-v9' ), count( $archive_drafts ) ) ); ?></span><span><?php esc_html_e( 'Woo query preserved', 'delicat-builder-v9' ); ?></span></div>
						</div>
						<div class="delicat-archive-card__actions"><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-product-archives' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Product Archive Builder', 'delicat-builder-v9' ); ?></a></div>
					</article>
				</div>
			</section>

			<section class="delicat-page-dashboard__stats" aria-label="<?php esc_attr_e( 'Page Builder summary', 'delicat-builder-v9' ); ?>">
				<div class="delicat-page-stat">
					<span><?php esc_html_e( 'Editable pages', 'delicat-builder-v9' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
				</div>
				<div class="delicat-page-stat delicat-page-stat--enabled">
					<span><?php esc_html_e( 'Builder enabled', 'delicat-builder-v9' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $enabled ) ); ?></strong>
				</div>
				<div class="delicat-page-stat">
					<span><?php esc_html_e( 'WordPress fallback', 'delicat-builder-v9' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( max( 0, $total - $enabled ) ) ); ?></strong>
				</div>
				<div class="delicat-page-stat">
					<span><?php esc_html_e( 'Draft / private', 'delicat-builder-v9' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( $drafts ) ); ?></strong>
				</div>
			</section>

			<section class="delicat-page-dashboard__workspace">
				<div class="delicat-page-toolbar">
					<div class="delicat-page-search">
						<span aria-hidden="true">⌕</span>
						<input type="search" id="delicat-page-search" placeholder="<?php esc_attr_e( 'Search pages…', 'delicat-builder-v9' ); ?>" autocomplete="off">
					</div>
					<div class="delicat-page-filters" role="group" aria-label="<?php esc_attr_e( 'Filter pages', 'delicat-builder-v9' ); ?>">
						<button type="button" class="is-active" data-delicat-page-filter="all"><?php esc_html_e( 'All', 'delicat-builder-v9' ); ?> <span><?php echo esc_html( (string) $total ); ?></span></button>
						<button type="button" data-delicat-page-filter="enabled"><?php esc_html_e( 'Enabled', 'delicat-builder-v9' ); ?> <span><?php echo esc_html( (string) $enabled ); ?></span></button>
						<button type="button" data-delicat-page-filter="disabled"><?php esc_html_e( 'Disabled', 'delicat-builder-v9' ); ?> <span><?php echo esc_html( (string) max( 0, $total - $enabled ) ); ?></span></button>
						<button type="button" data-delicat-page-filter="draft"><?php esc_html_e( 'Drafts', 'delicat-builder-v9' ); ?> <span><?php echo esc_html( (string) $drafts ); ?></span></button>
					</div>
				</div>

				<div class="delicat-page-picker" id="delicat-page-picker">
					<?php foreach ( $editable as $page ) :
						$is_enabled = Delicat_Builder_V9_Pages::is_enabled_for_page( $page->ID );
						$layout_raw = get_post_meta( $page->ID, Delicat_Builder_V9_Pages::META_LAYOUT, true );
						$sections   = is_array( $layout_raw ) ? min( Delicat_Builder_V9_Schema::MAX_SECTIONS, count( $layout_raw ) ) : 0;
						$builder_url = add_query_arg(
							array(
								'page'    => 'delicat-builder-v9-editor',
								'page_id' => $page->ID,
							),
							admin_url( 'admin.php' )
						);
						$preview = get_permalink( $page->ID );
						$edit    = get_edit_post_link( $page->ID, 'raw' );
						$status_label = get_post_status_object( $page->post_status );
						$status_name  = $status_label ? $status_label->label : ucfirst( $page->post_status );
						$is_home      = $homepage_id === (int) $page->ID;
						$filter_status = 'publish' === $page->post_status ? 'published' : 'draft';
						?>
						<article
							class="delicat-page-card<?php echo $is_enabled ? ' is-enabled' : ''; ?>"
							data-delicat-page-card
							data-builder-status="<?php echo $is_enabled ? 'enabled' : 'disabled'; ?>"
							data-post-status="<?php echo esc_attr( $filter_status ); ?>"
							data-search="<?php echo esc_attr( strtolower( wp_strip_all_tags( get_the_title( $page ) . ' ' . $page->post_name . ' ' . $page->ID ) ) ); ?>"
						>
							<div class="delicat-page-card__top">
								<div class="delicat-page-card__icon" aria-hidden="true"><?php echo esc_html( $is_home ? '⌂' : '▤' ); ?></div>
								<div class="delicat-page-card__badges">
									<span class="delicat-page-badge <?php echo $is_enabled ? 'is-enabled' : 'is-disabled'; ?>">
										<i aria-hidden="true"></i>
										<?php echo $is_enabled ? esc_html__( 'Builder enabled', 'delicat-builder-v9' ) : esc_html__( 'Builder disabled', 'delicat-builder-v9' ); ?>
									</span>
									<?php if ( $is_home ) : ?><span class="delicat-page-badge is-home"><?php esc_html_e( 'Homepage', 'delicat-builder-v9' ); ?></span><?php endif; ?>
								</div>
							</div>

							<div class="delicat-page-card__body">
								<h2><?php echo esc_html( get_the_title( $page ) ?: __( '(Untitled)', 'delicat-builder-v9' ) ); ?></h2>
								<p class="delicat-page-card__slug">/<?php echo esc_html( trim( $page->post_name, '/' ) ); ?>/</p>

								<div class="delicat-page-card__meta">
									<span><strong><?php echo esc_html( (string) $sections ); ?></strong> <?php esc_html_e( 'sections', 'delicat-builder-v9' ); ?></span>
									<span><?php echo esc_html( $status_name ); ?></span>
									<span><?php echo esc_html( sprintf( __( 'Updated %s', 'delicat-builder-v9' ), human_time_diff( get_post_modified_time( 'U', true, $page ), current_time( 'timestamp', true ) ) . ' ' . __( 'ago', 'delicat-builder-v9' ) ) ); ?></span>
								</div>
							</div>

							<div class="delicat-page-card__actions">
								<a class="button button-primary" href="<?php echo esc_url( $builder_url ); ?>"><?php esc_html_e( 'Open Builder', 'delicat-builder-v9' ); ?></a>
								<?php if ( $preview ) : ?><a class="button" href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'delicat-builder-v9' ); ?></a><?php endif; ?>
								<?php if ( $edit ) : ?><a class="button button-link" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'WP Edit', 'delicat-builder-v9' ); ?></a><?php endif; ?>
							</div>

							<form class="delicat-page-card__toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="delicat_builder_v9_toggle_page">
								<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page->ID ); ?>">
								<input type="hidden" name="enabled" value="<?php echo $is_enabled ? '0' : '1'; ?>">
								<?php wp_nonce_field( 'delicat_builder_v9_toggle_page_' . $page->ID ); ?>
								<button type="submit" class="delicat-page-toggle<?php echo $is_enabled ? ' is-on' : ''; ?>" aria-label="<?php echo esc_attr( $is_enabled ? __( 'Disable Builder for this page', 'delicat-builder-v9' ) : __( 'Enable Builder for this page', 'delicat-builder-v9' ) ); ?>">
									<span aria-hidden="true"></span>
									<strong><?php echo $is_enabled ? esc_html__( 'Enabled', 'delicat-builder-v9' ) : esc_html__( 'Disabled', 'delicat-builder-v9' ); ?></strong>
								</button>
							</form>
						</article>
					<?php endforeach; ?>
				</div>

				<div class="delicat-page-empty" id="delicat-page-empty" hidden>
					<div aria-hidden="true">⌕</div>
					<strong><?php esc_html_e( 'No pages match this filter.', 'delicat-builder-v9' ); ?></strong>
					<span><?php esc_html_e( 'Change the search or filter to see your pages.', 'delicat-builder-v9' ); ?></span>
				</div>
			</section>

			<section class="delicat-page-dashboard__security">
				<div>
					<strong><?php esc_html_e( 'Safe by design', 'delicat-builder-v9' ); ?></strong>
					<span><?php esc_html_e( 'Builder remains opt-in per page. Enable/disable actions use WordPress capabilities + nonces, and normal WordPress content remains the fallback.', 'delicat-builder-v9' ); ?></span>
				</div>
				<div class="delicat-page-dashboard__security-pills">
					<span>✓ Capability checks</span><span>✓ Nonce protected</span><span>✓ Compiled CSS</span><span>✓ No arbitrary JS</span>
				</div>
			</section>
		</div>
		<?php
	}

	public static function toggle_page(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}

		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		$enabled = isset( $_POST['enabled'] ) ? (int) rest_sanitize_boolean( wp_unslash( $_POST['enabled'] ) ) : 0;

		if ( ! $page_id || 'page' !== get_post_type( $page_id ) || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'You cannot change Builder status for this page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'delicat_builder_v9_toggle_page_' . $page_id );

		update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, $enabled ? 1 : 0 );

		if ( $enabled ) {
			$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
			if ( ! empty( $layout ) ) {
				Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );
			}
		}

		Delicat_Builder_V9_Cache::purge_page( $page_id );

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				$enabled ? 'builder_page_enabled' : 'builder_page_disabled',
				'info',
				array( 'page_id' => $page_id )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'delicat-builder-v9-editor',
					'status' => $enabled ? 'enabled' : 'disabled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function save(): void {
		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'You cannot edit this page.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_save_layout_' . $page_id, 'delicat_builder_nonce' );

		$layout = isset( $_POST['layout'] ) ? wp_unslash( $_POST['layout'] ) : '[]';
		if ( strlen( $layout ) > Delicat_Builder_V9_Schema::MAX_LAYOUT_BYTES ) {
			wp_die( esc_html__( 'The builder layout is too large. Reduce section content or image references.', 'delicat-builder-v9' ), 413 );
		}
		$result = Delicat_Builder_V9_Pages::save_layout( $page_id, $layout );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 400 );
		}

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			$current_layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_layout_saved',
				'info',
				array(
					'page_id'  => $page_id,
					'sections' => count( $current_layout ),
				)
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-editor', 'page_id' => $page_id, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function restore(): void {
		$page_id     = isset( $_GET['page_id'] ) ? absint( $_GET['page_id'] ) : 0;
		$revision_id = isset( $_GET['revision_id'] ) ? sanitize_text_field( wp_unslash( $_GET['revision_id'] ) ) : '';
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) || '' === $revision_id ) {
			wp_die( esc_html__( 'Invalid revision request.', 'delicat-builder-v9' ), 403 );
		}

		check_admin_referer( 'delicat_builder_v9_restore_' . $page_id . '_' . $revision_id );
		if ( ! Delicat_Builder_V9_Pages::restore_revision( $page_id, $revision_id ) ) {
			wp_die( esc_html__( 'Revision not found.', 'delicat-builder-v9' ), 404 );
		}

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_layout_restored',
				'notice',
				array(
					'page_id'     => $page_id,
					'revision_id' => substr( sanitize_key( $revision_id ), 0, 40 ),
				)
			);
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-editor', 'page_id' => $page_id, 'restored' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
