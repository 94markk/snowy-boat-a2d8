<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Release_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_delicat_builder_v9_snapshot_import', array( __CLASS__, 'snapshot_import' ) );
		add_action( 'admin_post_delicat_builder_v9_restore_pre_rc', array( __CLASS__, 'restore_pre_rc' ) );
		add_action( 'admin_post_delicat_builder_v9_undo_restore', array( __CLASS__, 'undo_restore' ) );
		add_action( 'admin_post_delicat_builder_v9_rc2_self_test', array( __CLASS__, 'run_self_test' ) );
		add_action( 'admin_post_delicat_builder_v9_rc2_manual_gate', array( __CLASS__, 'save_manual_gate' ) );
		add_action( 'admin_post_delicat_builder_v9_export_release_evidence', array( __CLASS__, 'export_release_evidence' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'RC Release Center', 'delicat-builder-v9' ),
			__( 'Release Center', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-release',
			array( __CLASS__, 'page' )
		);
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-release' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	private static function require_manage(): void {
		if ( ! Delicat_Builder_V9_Identity_Bridge::can_manage_global_security() ) {
			wp_die( esc_html__( 'You cannot manage Builder release operations.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
	}

	private static function validation_key(): string {
		return Delicat_Builder_V9_Release::VALIDATION_TRANSIENT_PREFIX . get_current_user_id();
	}

	public static function run_self_test(): void {
		self::require_manage();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_rc2_self_test' );

		$result = Delicat_Builder_V9_Release::self_test( true );
		Delicat_Builder_V9_Identity_Bridge::audit(
			'builder_rc2_self_test',
			! empty( $result['ok'] ) ? 'info' : 'warning',
			array(
				'passed' => ! empty( $result['ok'] ) ? 1 : 0,
				'checks' => count( $result['checks'] ?? array() ),
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'delicat-builder-v9-release',
					'self_test' => ! empty( $result['ok'] ) ? 'pass' : 'fail',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function save_manual_gate(): void {
		self::require_manage();

		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_rc2_manual_gate' );
		$input = isset( $_POST['manual_gate'] ) && is_array( $_POST['manual_gate'] )
			? wp_unslash( $_POST['manual_gate'] )
			: array();

		Delicat_Builder_V9_Release::save_manual_gate( $input );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'delicat-builder-v9-release',
					'manual_gate' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function export_release_evidence(): void {
		self::require_manage();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_export_release_evidence' );

		$evidence = Delicat_Builder_V9_Release::release_evidence();
		$json = wp_json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Could not generate release evidence.', 'delicat-builder-v9' ), '', array( 'response' => 500 ) );
		}

		Delicat_Builder_V9_Identity_Bridge::audit(
			'builder_rc2_release_evidence_exported',
			'info',
			array(
				'eligible' => ! empty( $evidence['stable_eligible'] ) ? 1 : 0,
				'blockers' => absint( $evidence['automated_gate']['blockers'] ?? 0 ),
			)
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="delicat-builder-v9-rc2-release-evidence-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function snapshot_import(): void {
		self::require_manage();

		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_snapshot_import' );

		$mode = isset( $_POST['snapshot_mode'] ) && 'apply' === sanitize_key( wp_unslash( $_POST['snapshot_mode'] ) )
			? 'apply'
			: 'validate';
		$allow_foreign = ! empty( $_POST['allow_foreign_host'] );

		if (
			empty( $_FILES['snapshot_file'] )
			|| ! is_array( $_FILES['snapshot_file'] )
			|| UPLOAD_ERR_OK !== (int) ( $_FILES['snapshot_file']['error'] ?? UPLOAD_ERR_NO_FILE )
		) {
			self::store_validation( false, __( 'Choose a valid Builder JSON snapshot.', 'delicat-builder-v9' ) );
			self::redirect();
		}

		$file = $_FILES['snapshot_file'];
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		$size = absint( $file['size'] ?? 0 );

		if ( $size < 2 || $size > 524288 || 'json' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			self::store_validation( false, __( 'Snapshot must be a JSON file no larger than 512 KB.', 'delicat-builder-v9' ) );
			self::redirect();
		}
		if ( ! is_uploaded_file( $tmp ) || ! is_readable( $tmp ) ) {
			self::store_validation( false, __( 'Uploaded snapshot could not be read safely.', 'delicat-builder-v9' ) );
			self::redirect();
		}

		$raw = file_get_contents( $tmp );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			self::store_validation( false, __( 'Snapshot JSON is invalid.', 'delicat-builder-v9' ) );
			self::redirect();
		}

		$validated = Delicat_Builder_V9_Release::sanitize_snapshot( $data, $allow_foreign );
		if ( is_wp_error( $validated ) ) {
			self::store_validation( false, $validated->get_error_message() );
			self::redirect();
		}

		if ( 'validate' === $mode ) {
			self::store_validation(
				true,
				sprintf(
					/* translators: 1: source version, 2: option groups. */
					__( 'Snapshot validated. Source %1$s; %2$d supported option groups are safe to restore.', 'delicat-builder-v9' ),
					$validated['source_version'] ?: __( 'unknown', 'delicat-builder-v9' ),
					count( $validated['options'] )
				)
			);
			self::redirect();
		}

		Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change( 'rc_snapshot_restore' );
		$result = Delicat_Builder_V9_Release::apply_snapshot( $data, $allow_foreign );
		if ( is_wp_error( $result ) ) {
			self::store_validation( false, $result->get_error_message() );
			self::redirect();
		}

		self::store_validation(
			true,
			sprintf(
				/* translators: %d: number of restored option groups. */
				__( 'Configuration restored successfully: %d option groups applied. Safe Mode state was intentionally left unchanged.', 'delicat-builder-v9' ),
				absint( $result['options'] )
			)
		);
		self::redirect();
	}

	public static function restore_pre_rc(): void {
		self::restore_backup( Delicat_Builder_V9_Release::PRE_RC_BACKUP_OPTION, 'pre_rc' );
	}

	public static function undo_restore(): void {
		self::restore_backup( Delicat_Builder_V9_Release::LAST_RESTORE_BACKUP_OPTION, 'undo' );
	}

	private static function restore_backup( string $option, string $kind ): void {
		self::require_manage();
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_restore_' . $kind );
		Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change( 'rc_backup_restore' );

		$snapshot = Delicat_Builder_V9_Release::backup_snapshot( $option );
		if ( is_wp_error( $snapshot ) ) {
			self::store_validation( false, $snapshot->get_error_message() );
			self::redirect();
		}

		$result = Delicat_Builder_V9_Release::apply_snapshot( $snapshot, true );
		if ( is_wp_error( $result ) ) {
			self::store_validation( false, $result->get_error_message() );
		} else {
			self::store_validation(
				true,
				sprintf(
					__( 'Backup restored: %d Builder option groups applied.', 'delicat-builder-v9' ),
					absint( $result['options'] )
				)
			);
		}
		self::redirect();
	}

	private static function store_validation( bool $ok, string $message ): void {
		set_transient(
			self::validation_key(),
			array(
				'ok'      => $ok,
				'message' => sanitize_text_field( $message ),
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function redirect(): void {
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'delicat-builder-v9-release' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function page(): void {
		self::require_manage();

		$gate = Delicat_Builder_V9_Release::gate_summary();
		$self_test = Delicat_Builder_V9_Release::self_test( false );
		$manual = Delicat_Builder_V9_Release::manual_gate_summary();
		$stable = Delicat_Builder_V9_Release::stable_eligibility();
		$validation = get_transient( self::validation_key() );
		if ( is_array( $validation ) ) {
			delete_transient( self::validation_key() );
		}
		$pre_backup = get_option( Delicat_Builder_V9_Release::PRE_RC_BACKUP_OPTION, array() );
		$last_backup = get_option( Delicat_Builder_V9_Release::LAST_RESTORE_BACKUP_OPTION, array() );
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">RC 36 · VISUAL DESIGN SYSTEM</span>
					<h1><?php esc_html_e( 'Release Center', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Final self-tests, WordPress Site Health integration, manual staging evidence, compatibility gates and rollback validation before promotion to 9.0.0 Stable.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( is_array( $validation ) ) : ?>
				<div class="notice <?php echo ! empty( $validation['ok'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $validation['message'] ); ?></p></div>
			<?php endif; ?>

			<?php $self_test_notice = isset( $_GET['self_test'] ) ? sanitize_key( wp_unslash( $_GET['self_test'] ) ) : ''; ?>
			<?php if ( '' !== $self_test_notice ) : ?>
				<div class="notice <?php echo 'pass' === $self_test_notice ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo 'pass' === $self_test_notice ? esc_html__( 'RC 36 self-test passed.', 'delicat-builder-v9' ) : esc_html__( 'RC 36 self-test found an item that needs review.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['manual_gate'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Manual staging evidence saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<section class="delicat-admin__card" style="margin-bottom:20px">
				<h2><?php esc_html_e( 'Stable 9.0.0 eligibility', 'delicat-builder-v9' ); ?></h2>
				<p><strong><?php echo ! empty( $stable['eligible'] ) ? esc_html__( 'ELIGIBLE — RC automated and manual release gates are complete.', 'delicat-builder-v9' ) : esc_html__( 'NOT ELIGIBLE YET — do not label this build Stable until the remaining gates pass.', 'delicat-builder-v9' ); ?></strong></p>
				<p><?php echo esc_html( sprintf( __( 'Automated blockers: %1$d · Manual staging evidence: %2$d/%3$d · Self-test: %4$s', 'delicat-builder-v9' ), absint( $gate['blockers'] ), absint( $manual['completed'] ), absint( $manual['total'] ), ! empty( $self_test['ok'] ) ? 'PASS' : 'REVIEW' ) ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_rc2_self_test' ), 'delicat_builder_v9_rc2_self_test' ) ); ?>"><?php esc_html_e( 'Run RC 36 self-test now', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_export_release_evidence' ), 'delicat_builder_v9_export_release_evidence' ) ); ?>"><?php esc_html_e( 'Download release evidence', 'delicat-builder-v9' ); ?></a>
				</p>
			</section>

			<div class="delicat-admin__grid">
				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'RC release gate', 'delicat-builder-v9' ); ?></h2>
					<p>
						<strong>
							<?php
							echo $gate['ready']
								? esc_html__( 'PASS — no blocking compatibility issue detected.', 'delicat-builder-v9' )
								: esc_html( sprintf( __( 'NOT READY — %d blocker(s) detected.', 'delicat-builder-v9' ), absint( $gate['blockers'] ) ) );
							?>
						</strong>
					</p>
					<p><?php echo esc_html( sprintf( __( '%1$d blockers · %2$d warnings', 'delicat-builder-v9' ), absint( $gate['blockers'] ), absint( $gate['warnings'] ) ) ); ?></p>

					<div class="delicat-status-list">
						<?php foreach ( $gate['rows'] as $item ) : ?>
							<?php $ok = 'pass' === $item['status']; ?>
							<div class="delicat-status">
								<span class="delicat-status__dot <?php echo $ok ? 'is-ok' : 'is-warn'; ?>"></span>
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span><?php echo esc_html( $item['text'] ); ?><?php echo 'blocker' === $item['status'] ? ' · BLOCKER' : ( 'warning' === $item['status'] ? ' · REVIEW' : '' ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'RC migration & rollback', 'delicat-builder-v9' ); ?></h2>
					<p><?php echo esc_html( sprintf( __( 'Schema: %s', 'delicat-builder-v9' ), Delicat_Builder_V9_Release::schema_version() ?: __( 'not migrated', 'delicat-builder-v9' ) ) ); ?></p>

					<?php if ( is_array( $pre_backup ) && ! empty( $pre_backup['snapshot'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Pre-RC configuration backup:', 'delicat-builder-v9' ); ?></strong> <?php echo esc_html( (string) ( $pre_backup['created_at'] ?? '' ) ); ?></p>
						<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_restore_pre_rc' ), 'delicat_builder_v9_restore_pre_rc' ) ); ?>"><?php esc_html_e( 'Restore pre-RC configuration', 'delicat-builder-v9' ); ?></a></p>
					<?php endif; ?>

					<?php if ( is_array( $last_backup ) && ! empty( $last_backup['snapshot'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Last pre-restore backup:', 'delicat-builder-v9' ); ?></strong> <?php echo esc_html( (string) ( $last_backup['created_at'] ?? '' ) ); ?></p>
						<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_undo_restore' ), 'delicat_builder_v9_restore_undo' ) ); ?>"><?php esc_html_e( 'Undo last configuration restore', 'delicat-builder-v9' ); ?></a></p>
					<?php endif; ?>

					<p class="description"><?php esc_html_e( 'RC1 does not rewrite page-layout schema. Existing Builder page revisions remain the layout rollback system; the RC migration backup covers non-secret global Builder settings.', 'delicat-builder-v9' ); ?></p>
				</section>
			</div>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'RC 36 self-test', 'delicat-builder-v9' ); ?></h2>
				<div class="delicat-status-list">
					<?php foreach ( (array) ( $self_test['checks'] ?? array() ) as $key => $check ) : ?>
						<div class="delicat-status">
							<span class="delicat-status__dot <?php echo ! empty( $check['ok'] ) ? 'is-ok' : 'is-warn'; ?>"></span>
							<strong><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $key ) ) ); ?></strong>
							<span><?php echo esc_html( (string) ( $check['text'] ?? '' ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Manual staging evidence', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Only mark a check after you actually tested it on staging. These confirmations are deliberately manual because a plugin cannot safely fake payment, supplier, mobile-device or external-login success.', 'delicat-builder-v9' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="delicat_builder_v9_rc2_manual_gate">
					<?php wp_nonce_field( 'delicat_builder_v9_rc2_manual_gate' ); ?>
					<?php foreach ( Delicat_Builder_V9_Release::manual_gate_definitions() as $key => $label ) : ?>
						<label class="delicat-switch">
							<input type="checkbox" name="manual_gate[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $manual['gate'][ $key ] ) ); ?>>
							<span><strong><?php echo esc_html( $label ); ?></strong></span>
						</label>
					<?php endforeach; ?>
					<?php submit_button( __( 'Save staging evidence', 'delicat-builder-v9' ) ); ?>
				</form>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Validate / restore a safe Builder snapshot', 'delicat-builder-v9' ); ?></h2>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="delicat_builder_v9_snapshot_import">
					<?php wp_nonce_field( 'delicat_builder_v9_snapshot_import' ); ?>

					<p><input type="file" name="snapshot_file" accept="application/json,.json" required></p>
					<label>
						<input type="checkbox" name="allow_foreign_host" value="1">
						<?php esc_html_e( 'Allow a snapshot from a different hostname (for staging → production migration).', 'delicat-builder-v9' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Only known Builder option groups are accepted and every value is re-sanitized. Safe Mode is not imported, and customer/order/API/Identity provider secrets are not part of Builder snapshots.', 'delicat-builder-v9' ); ?></p>

					<p>
						<button class="button" type="submit" name="snapshot_mode" value="validate"><?php esc_html_e( 'Validate only', 'delicat-builder-v9' ); ?></button>
						<button class="button button-primary" type="submit" name="snapshot_mode" value="apply"><?php esc_html_e( 'Validate and restore', 'delicat-builder-v9' ); ?></button>
					</p>
				</form>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'RC 36 compatibility policy', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Cart/Checkout Blocks compatibility is declared through WooCommerce FeaturesUtil. Product Collection, Cart and Checkout receive small per-block styles only when their respective Delicat presentation engine is enabled. Elementor/Block Theme + automatic Shell combinations are warnings rather than destructive automatic changes.', 'delicat-builder-v9' ); ?></p>
				<p><?php esc_html_e( 'LiteSpeed Cache normally excludes correctly assigned WooCommerce Cart, Checkout and My Account pages itself; Release Center therefore verifies page assignment and keeps Production Center no-store response headers as defense-in-depth instead of editing LiteSpeed settings automatically.', 'delicat-builder-v9' ); ?></p>
			</section>
		</div>
		<?php
	}
}
