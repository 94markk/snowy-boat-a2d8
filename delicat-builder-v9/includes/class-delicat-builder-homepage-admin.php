<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Homepage_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 19 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_apply', array( __CLASS__, 'apply' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_restore', array( __CLASS__, 'restore' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_repair', array( __CLASS__, 'repair' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_reference', array( __CLASS__, 'reference' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_info', array( __CLASS__, 'info' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_fast', array( __CLASS__, 'fast' ) );
		add_action( 'admin_post_delicat_builder_v9_homepage_disable', array( __CLASS__, 'disable_builder_page' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Homepage Studio', 'delicat-builder-v9' ),
			__( 'Homepage Studio', 'delicat-builder-v9' ),
			'edit_pages',
			'delicat-builder-v9-homepage',
			array( __CLASS__, 'page' )
		);
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-homepage' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'delicat-builder-v9-admin', DELICAT_BUILDER_V9_URL . 'assets/css/admin.css', array(), DELICAT_BUILDER_V9_VERSION );
	}

	private static function page_id(): int {
		if ( isset( $_REQUEST['page_id'] ) ) {
			return absint( wp_unslash( $_REQUEST['page_id'] ) );
		}
		$test = get_page_by_path( 'test-2', OBJECT, 'page' );
		return $test instanceof WP_Post ? (int) $test->ID : 0;
	}

	public static function apply(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		$page_id = self::page_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Invalid homepage staging page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_homepage_apply_' . $page_id );
		$result = Delicat_Builder_V9_Homepage::apply( $page_id );
		$status = is_wp_error( $result ) ? 'error' : 'applied';
		$message = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';
		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-homepage', 'page_id' => $page_id, 'status' => $status, 'message' => $message ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function repair(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		$page_id = self::page_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Invalid homepage staging page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_homepage_repair_' . $page_id );
		$result = Delicat_Builder_V9_Homepage::repair( $page_id );
		$status = is_wp_error( $result ) ? 'error' : 'repaired';
		$message = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'delicat-builder-v9-homepage',
					'page_id' => $page_id,
					'status'  => $status,
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}


	public static function reference(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		$page_id = self::page_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Invalid homepage staging page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_homepage_reference_' . $page_id );
		$result = Delicat_Builder_V9_Homepage::apply_lovable_reference( $page_id );
		$status = is_wp_error( $result ) ? 'error' : 'reference';
		$message = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'delicat-builder-v9-homepage',
					'page_id' => $page_id,
					'status'  => $status,
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

public static function info(): void {
	if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
		wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
	}
	$page_id = self::page_id();
	if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
		wp_die( esc_html__( 'Invalid homepage staging page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'delicat_builder_v9_homepage_info_' . $page_id );

	$result = Delicat_Builder_V9_Homepage::apply_reference_info_sections( $page_id );
	$status = is_wp_error( $result ) ? 'error' : 'info';
	$message = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'delicat-builder-v9-homepage',
				'page_id' => $page_id,
				'status'  => $status,
				'message' => $message,
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

	public static function fast(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		$page_id = self::page_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to apply the performance profile.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_homepage_fast_' . $page_id );

		Delicat_Builder_V9_Performance::apply_ultra_fast_profile();
		$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
		if ( ! empty( $layout ) ) {
			Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );
		}
		Delicat_Builder_V9_Cache::purge_page( $page_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'delicat-builder-v9-homepage',
					'page_id' => $page_id,
					'status'  => 'fast',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function disable_builder_page(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}

		$page_id = self::page_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Invalid homepage page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'delicat_builder_v9_homepage_disable_' . $page_id );
		update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 0 );
		Delicat_Builder_V9_Cache::purge_page( $page_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'delicat-builder-v9-homepage',
					'page_id' => $page_id,
					'status'  => 'disabled',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function restore(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		$page_id = self::page_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			wp_die( esc_html__( 'Invalid homepage staging page.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_homepage_restore_' . $page_id );
		$result = Delicat_Builder_V9_Homepage::restore( $page_id );
		$status = is_wp_error( $result ) ? 'error' : 'restored';
		$message = is_wp_error( $result ) ? rawurlencode( $result->get_error_message() ) : '';
		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-homepage', 'page_id' => $page_id, 'status' => $status, 'message' => $message ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page(): void {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}
		$page_id = self::page_id();
		$pages = get_pages( array( 'number' => 100, 'sort_column' => 'post_title', 'sort_order' => 'ASC' ) );
		$products = class_exists( 'WooCommerce' ) ? Delicat_Builder_V9_Homepage::detected_products() : array();
		$diagnostics = $page_id ? Delicat_Builder_V9_Homepage::diagnostics( $page_id ) : array();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$message = isset( $_GET['message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['message'] ) ) ) : '';
		$runtime_failure = get_option( 'delicat_builder_v9_last_runtime_failure', array() );
		$runtime_failure = is_array( $runtime_failure ) ? $runtime_failure : array();
		$front_page_id = absint( get_option( 'page_on_front' ) );
		$is_live_front_page = $page_id > 0 && $front_page_id === $page_id;
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">RC 36 · TEST HOMEPAGE WORKFLOW</span>
					<h1><?php esc_html_e( 'Homepage Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Build the new homepage safely on Test 2 first. The original WordPress content stays underneath and the Design Studio is scoped to the selected test page only.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( 'applied' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Homepage starter applied. Open the Test 2 page and Visual Builder to continue.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'restored' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pre-starter Builder/design state restored.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'repaired' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Homepage layout repaired, recompiled and page cache purged.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'reference' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Lovable storefront reference applied: synchronized carousels, product CTAs, compact mobile spacing, recompile and cache purge complete.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'info' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Comment ça marche, testimonials, FAQ and trust sections added without changing saved product selections.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'fast' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ultra Fast Homepage profile applied and Test 2 cache purged.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'disabled' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Builder output disabled for this page. Original WordPress/Elementor content is active again.', 'delicat-builder-v9' ); ?></p></div><?php endif; ?>
			<?php if ( 'error' === $status ) : ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>

			<section class="delicat-admin__card">
				<h2><?php esc_html_e( '1. Choose the staging page', 'delicat-builder-v9' ); ?></h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="delicat-builder-v9-homepage">
					<select name="page_id">
						<?php foreach ( $pages as $page ) : ?>
							<?php if ( ! current_user_can( 'edit_post', $page->ID ) ) { continue; } ?>
							<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $page_id, $page->ID ); ?>><?php echo esc_html( $page->post_title . ' (#' . $page->ID . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button" type="submit"><?php esc_html_e( 'Use this page', 'delicat-builder-v9' ); ?></button>
				</form>
				<?php if ( $page_id ) : ?><p><strong><?php echo esc_html( get_the_title( $page_id ) ); ?></strong> — <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_permalink( $page_id ) ); ?></a></p><?php endif; ?>
			</section>

			<?php if ( $is_live_front_page ) : ?>
				<div class="notice notice-warning" style="margin:20px 0 0">
					<p><strong><?php esc_html_e( 'Live homepage selected.', 'delicat-builder-v9' ); ?></strong>
					<?php esc_html_e( 'RC24 will validate and compile the candidate layout before enabling Builder output. If validation fails, the previous homepage state is restored automatically.', 'delicat-builder-v9' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="delicat-admin__grid" style="margin-top:20px">
				<section class="delicat-admin__card">
					<h2><?php esc_html_e( '2. Starter layout', 'delicat-builder-v9' ); ?></h2>
					<p><?php esc_html_e( 'The starter adds exactly these homepage blocks:', 'delicat-builder-v9' ); ?></p>
					<p><strong>Category chips → JEUX 🎮 → ABONNEMENT PREMIUM 👑 → ÉCHANGES 💵 → GIFT CARDS 🎁</strong></p>
					<p><?php esc_html_e( 'Only the two approved carousel styles are used: Delicat Jeux and Delicat Abonnement Premium.', 'delicat-builder-v9' ); ?></p>
					<?php if ( $page_id ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="delicat_builder_v9_homepage_apply">
							<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
							<?php wp_nonce_field( 'delicat_builder_v9_homepage_apply_' . $page_id ); ?>
							<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply starter to this page', 'delicat-builder-v9' ); ?></button>
						</form>
					<?php endif; ?>
				</section>

				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'Product detection', 'delicat-builder-v9' ); ?></h2>
					<?php if ( empty( $products ) ) : ?>
						<p><?php esc_html_e( 'WooCommerce is not active.', 'delicat-builder-v9' ); ?></p>
					<?php else : ?>
						<div class="delicat-status-list">
							<?php foreach ( array( 'games' => 'Jeux', 'subscriptions' => 'Abonnement', 'exchange' => 'Échanges', 'gift_cards' => 'Gift Cards' ) as $key => $label ) : ?>
								<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $products[ $key ] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong><?php echo esc_html( $label ); ?></strong><span><?php echo esc_html( count( $products[ $key ] ?? array() ) . ' produit(s) détecté(s)' ); ?></span></div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>
			</div>

			<?php if ( $page_id ) : ?>
			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( '3. Layout health & repair', 'delicat-builder-v9' ); ?></h2>
				<div class="delicat-status-list">
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['enabled'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Builder page</strong><span><?php echo ! empty( $diagnostics['enabled'] ) ? 'Enabled' : 'Disabled'; ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['sections'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Layout</strong><span><?php echo esc_html( absint( $diagnostics['sections'] ?? 0 ) . ' section(s)' ); ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['design_match'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Design scope</strong><span><?php echo ! empty( $diagnostics['design_match'] ) ? 'Test page matched' : 'Needs repair'; ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['reference_preset'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Lovable reference</strong><span><?php echo ! empty( $diagnostics['reference_preset'] ) ? 'Active' : 'Optional'; ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo absint( $diagnostics['product_sections'] ?? 0 ) >= 4 ? 'is-ok' : 'is-warn'; ?>"></span><strong>Product sections</strong><span><?php echo esc_html( absint( $diagnostics['product_sections'] ?? 0 ) . ' carousel(s)' ); ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo absint( $diagnostics['info_sections'] ?? 0 ) >= 4 ? 'is-ok' : 'is-warn'; ?>"></span><strong>Reference info sections</strong><span><?php echo esc_html( absint( $diagnostics['info_sections'] ?? 0 ) . ' / 4' ); ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['wc_reviews'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Avis WooCommerce (étoiles Google)</strong><span><?php echo ! empty( $diagnostics['wc_reviews'] ) ? 'Activés — les fiches produit publient Product + AggregateRating' : 'Désactivés — WooCommerce → Réglages → Produits : activer les avis et la notation par étoiles'; ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['products_css_readable'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Carousel CSS</strong><span><?php echo ! empty( $diagnostics['products_css_readable'] ) ? 'Readable' : 'Missing'; ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo ! empty( $diagnostics['design_css_readable'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Design CSS</strong><span><?php echo ! empty( $diagnostics['design_css_readable'] ) ? 'Readable' : 'Missing'; ?></span></div>
					<div class="delicat-status"><span class="delicat-status__dot <?php echo empty( $diagnostics['legacy_shortcode'] ) ? 'is-ok' : 'is-warn'; ?>"></span><strong>Old shortcode in page content</strong><span><?php echo empty( $diagnostics['legacy_shortcode'] ) ? 'None' : 'Present but safely suppressed'; ?></span></div>
				</div>

				<p style="margin-top:14px"><strong><?php esc_html_e( 'Lovable reference order:', 'delicat-builder-v9' ); ?></strong> <?php esc_html_e( 'Meilleures ventes → Jeux → Abonnement Premium → Gift Cards → Échanges & Services → Comment ça marche → Ils nous font confiance → FAQ → Trust strip. The extra content hero/category rail is removed to match the supplied Lovable mobile reference; product selections are preserved.', 'delicat-builder-v9' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
					<input type="hidden" name="action" value="delicat_builder_v9_homepage_reference">
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
					<?php wp_nonce_field( 'delicat_builder_v9_homepage_reference_' . $page_id ); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply Lovable exact sections + 2.5-card mobile peek + repair', 'delicat-builder-v9' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
					<input type="hidden" name="action" value="delicat_builder_v9_homepage_info">
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
					<?php wp_nonce_field( 'delicat_builder_v9_homepage_info_' . $page_id ); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Add Lovable info sections only + recompile + purge cache', 'delicat-builder-v9' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
					<input type="hidden" name="action" value="delicat_builder_v9_homepage_fast">
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
					<?php wp_nonce_field( 'delicat_builder_v9_homepage_fast_' . $page_id ); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Apply Ultra Fast Homepage profile + purge cache', 'delicat-builder-v9' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
					<input type="hidden" name="action" value="delicat_builder_v9_homepage_repair">
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
					<?php wp_nonce_field( 'delicat_builder_v9_homepage_repair_' . $page_id ); ?>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Repair layout + recompile + purge Test 2 cache', 'delicat-builder-v9' ); ?></button>
				</form>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Runtime safety', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'RC24 isolates Builder rendering errors and falls back to the original WordPress page instead of allowing a Builder exception to become a public critical-error screen.', 'delicat-builder-v9' ); ?></p>
				<?php if ( ! empty( $runtime_failure ) ) : ?>
					<div class="delicat-status-list">
						<div class="delicat-status"><span class="delicat-status__dot is-warn"></span><strong><?php esc_html_e( 'Last Builder runtime event', 'delicat-builder-v9' ); ?></strong><span><?php echo esc_html( (string) ( $runtime_failure['time'] ?? '' ) ); ?></span></div>
						<div class="delicat-status"><span class="delicat-status__dot is-warn"></span><strong><?php esc_html_e( 'Stage', 'delicat-builder-v9' ); ?></strong><span><?php echo esc_html( (string) ( $runtime_failure['stage'] ?? '' ) ); ?></span></div>
						<div class="delicat-status"><span class="delicat-status__dot is-warn"></span><strong><?php esc_html_e( 'Type', 'delicat-builder-v9' ); ?></strong><span><?php echo esc_html( (string) ( $runtime_failure['type'] ?? '' ) ); ?></span></div>
						<div class="delicat-status"><span class="delicat-status__dot is-warn"></span><strong><?php esc_html_e( 'Reference hash', 'delicat-builder-v9' ); ?></strong><span><code><?php echo esc_html( substr( (string) ( $runtime_failure['hash'] ?? '' ), 0, 16 ) ); ?></code></span></div>
					</div>
				<?php else : ?>
					<div class="delicat-status"><span class="delicat-status__dot is-ok"></span><strong><?php esc_html_e( 'No Builder runtime failure recorded', 'delicat-builder-v9' ); ?></strong><span><?php esc_html_e( 'Ready', 'delicat-builder-v9' ); ?></span></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
					<input type="hidden" name="action" value="delicat_builder_v9_homepage_disable">
					<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
					<?php wp_nonce_field( 'delicat_builder_v9_homepage_disable_' . $page_id ); ?>
					<button class="button" type="submit"><?php esc_html_e( 'Emergency: disable Builder output for this page', 'delicat-builder-v9' ); ?></button>
				</form>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( '4. Continue editing', 'delicat-builder-v9' ); ?></h2>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'delicat-builder-v9-editor', 'page_id' => $page_id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Open Visual Builder', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( Delicat_Builder_V9_Cache::preview_url( $page_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open fresh uncached preview', 'delicat-builder-v9' ); ?></a>
				</p>
				<?php if ( get_post_meta( $page_id, Delicat_Builder_V9_Homepage::BACKUP_META, true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="delicat_builder_v9_homepage_restore">
						<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $page_id ); ?>">
						<?php wp_nonce_field( 'delicat_builder_v9_homepage_restore_' . $page_id ); ?>
						<button class="button" type="submit"><?php esc_html_e( 'Restore state from before homepage starter', 'delicat-builder-v9' ); ?></button>
					</form>
				<?php endif; ?>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
