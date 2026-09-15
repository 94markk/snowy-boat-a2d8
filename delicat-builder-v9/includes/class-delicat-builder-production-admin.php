<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Production_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_delicat_builder_v9_production_save', array( __CLASS__, 'save_policy' ) );
		add_action( 'admin_post_delicat_builder_v9_safe_mode', array( __CLASS__, 'toggle_safe_mode' ) );
		add_action( 'admin_post_delicat_builder_v9_integrity_scan', array( __CLASS__, 'run_integrity_scan' ) );
		add_action( 'admin_post_delicat_builder_v9_export_config', array( __CLASS__, 'export_config' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Production Center', 'delicat-builder-v9' ),
			__( 'Production Center', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-production',
			array( __CLASS__, 'page' )
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$profile = in_array( $input['csp_profile'] ?? '', array( 'compatibility', 'strict-monitor' ), true )
			? $input['csp_profile']
			: 'compatibility';

		$clean = array(
			'enabled'                  => empty( $input['enabled'] ) ? 0 : 1,
			'private_cache_headers'    => empty( $input['private_cache_headers'] ) ? 0 : 1,
			'csp_report_only'          => empty( $input['csp_report_only'] ) ? 0 : 1,
			'csp_profile'              => $profile,
			'integrity_scan_cache_min' => min( 60, max( 5, absint( $input['integrity_scan_cache_min'] ?? 15 ) ) ),
		);

		return $clean;
	}

	public static function save_policy(): void {
		self::require_manage();

		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}

		check_admin_referer( 'delicat_builder_v9_production_save' );
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'require_recent_for_security_change' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change( 'production_policy' );
		}

		$input = isset( $_POST['production'] ) && is_array( $_POST['production'] )
			? wp_unslash( $_POST['production'] )
			: array();

		$clean = self::sanitize( $input );
		update_option( Delicat_Builder_V9_Production::OPTION, $clean, false );
		Delicat_Builder_V9_Production::reset_settings_cache();

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_production_policy_updated',
				'warning',
				array(
					'enabled'       => $clean['enabled'],
					'private_cache' => $clean['private_cache_headers'],
					'csp_report'    => $clean['csp_report_only'],
					'csp_profile'   => $clean['csp_profile'],
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'delicat-builder-v9-production',
					'saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-production' !== $hook ) {
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
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'can_manage_global_security' ) ) ) {
			if ( Delicat_Builder_V9_Identity_Bridge::can_manage_global_security() ) {
				return;
			}
		} elseif ( current_user_can( 'manage_options' ) ) {
			// Recovery fallback only when the optional Identity bridge is unavailable.
			// Keep this stricter than normal Woo management: administrator only.
			return;
		}
		wp_die( esc_html__( 'You cannot manage Builder production security.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
	}

	private static function disable_preflight(): array {
		$failures = array();

		if ( PHP_VERSION_ID < 80500 ) {
			$failures[] = 'php_version';
		}

		try {
			if ( ! class_exists( 'Delicat_Builder_V9_Performance', false ) || ! is_callable( array( 'Delicat_Builder_V9_Performance', 'self_test' ) ) ) {
				$failures[] = 'performance_module';
			} else {
				$performance = Delicat_Builder_V9_Performance::self_test();
				if ( empty( $performance['ok'] ) ) {
					$failures[] = 'performance_assets';
				}
			}
		} catch ( Throwable $error ) {
			$failures[] = 'performance_exception';
		}

		try {
			if ( ! class_exists( 'Delicat_Builder_V9_Shell', false ) || ! is_callable( array( 'Delicat_Builder_V9_Shell', 'self_test' ) ) ) {
				$failures[] = 'shell_module';
			} else {
				$shell = Delicat_Builder_V9_Shell::self_test();
				if ( empty( $shell['ok'] ) ) {
					$failures[] = 'shell_runtime';
				}
			}
		} catch ( Throwable $error ) {
			$failures[] = 'shell_exception';
		}

		$load_failures = get_option( 'delicat_builder_v9_module_load_failures', array() );
		if ( is_array( $load_failures ) && ! empty( $load_failures ) ) {
			$last = end( $load_failures );
			$time = is_array( $last ) ? strtotime( (string) ( $last['time'] ?? '' ) ) : false;
			if ( $time && $time >= ( time() - 10 * MINUTE_IN_SECONDS ) ) {
				$failures[] = 'recent_module_load_failure';
			}
		}

		return array_values( array_unique( $failures ) );
	}

	public static function toggle_safe_mode(): void {
		self::require_manage();
		check_admin_referer( 'delicat_builder_v9_safe_mode' );

		$enable = isset( $_GET['enable'] ) && '1' === (string) $_GET['enable'];
		if ( ! $enable ) {
			$preflight = self::disable_preflight();
			if ( ! empty( $preflight ) ) {
				update_option( Delicat_Builder_V9_Production::SAFE_MODE_OPTION, 1, false );
				update_option( 'delicat_builder_v9_safe_mode_meta', array( 'version' => DELICAT_BUILDER_V9_VERSION, 'tripped' => gmdate( 'c' ), 'context' => 'preflight_blocked' ), false );
				update_option(
					'delicat_builder_v9_last_preflight_failure',
					array(
						'time'  => gmdate( 'c' ),
						'items' => array_slice( array_map( 'sanitize_key', $preflight ), 0, 8 ),
					),
					false
				);
				wp_safe_redirect(
					add_query_arg(
						array( 'page' => 'delicat-builder-v9-production', 'safe_mode' => 'blocked' ),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
		}

		update_option( Delicat_Builder_V9_Production::SAFE_MODE_OPTION, $enable ? 1 : 0, false );
		if ( $enable ) {
			/* RC51.59: stamp manual trips so version-based self-heal respects them
			 * for the lifetime of this build. */
			update_option(
				'delicat_builder_v9_safe_mode_meta',
				array(
					'version' => DELICAT_BUILDER_V9_VERSION,
					'tripped' => gmdate( 'c' ),
					'context' => 'manual_admin',
				),
				false
			);
		}

		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				$enable ? 'builder_safe_mode_enabled' : 'builder_safe_mode_disabled',
				$enable ? 'warning' : 'notice',
				array()
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'delicat-builder-v9-production',
					'safe_mode' => $enable ? 'on' : 'off',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function run_integrity_scan(): void {
		self::require_manage();
		check_admin_referer( 'delicat_builder_v9_integrity_scan' );

		$result = Delicat_Builder_V9_Production::integrity_scan( true );
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_integrity_scan',
				! empty( $result['ok'] ) ? 'info' : 'warning',
				array(
					'checked'  => absint( $result['checked'] ?? 0 ),
					'modified' => count( $result['modified'] ?? array() ),
					'missing'  => count( $result['missing'] ?? array() ),
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'delicat-builder-v9-production',
					'integrity' => ! empty( $result['ok'] ) ? 'ok' : 'warn',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function export_config(): void {
		self::require_manage();
		check_admin_referer( 'delicat_builder_v9_export_config' );

		$snapshot = Delicat_Builder_V9_Production::config_snapshot();
		$json = wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			wp_die( esc_html__( 'Could not generate configuration snapshot.', 'delicat-builder-v9' ), '', array( 'response' => 500 ) );
		}

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_config_snapshot_exported',
				'notice',
				array( 'version' => DELICAT_BUILDER_V9_VERSION )
			);
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="delicat-builder-v9-config-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function page(): void {
		self::require_manage();

		$s = Delicat_Builder_V9_Production::settings();
		$diagnostics = Delicat_Builder_V9_Production::diagnostics();
		$integrity = Delicat_Builder_V9_Production::integrity_scan();
		$safe_mode = Delicat_Builder_V9_Core::is_safe_mode();
		$last_fatal = get_option( 'delicat_builder_v9_last_bootstrap_fatal', array() );
		$last_fatal = is_array( $last_fatal ) ? $last_fatal : array();
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">PRODUCTION HARDENING · RC 39.7 STAGED WOO + FATAL RECOVERY</span>
					<h1><?php esc_html_e( 'Production Center', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Release-readiness diagnostics, emergency compatibility mode, code-integrity verification, safe configuration snapshots and CSP Report-Only monitoring without changing WooCommerce or Identity authority.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Production policy saved after security authorization.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['safe_mode'] ) ) : ?>
				<?php $safe_mode_notice = sanitize_key( wp_unslash( $_GET['safe_mode'] ) ); ?>
				<?php if ( 'blocked' === $safe_mode_notice ) : ?>
					<div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e( 'Safe Mode stayed ON.', 'delicat-builder-v9' ); ?></strong> <?php esc_html_e( 'RC39.11 preflight detected a module/runtime problem before exposing the storefront to the full shell.', 'delicat-builder-v9' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-success is-dismissible"><p><?php echo 'on' === $safe_mode_notice ? esc_html__( 'Compatibility Safe Mode enabled.', 'delicat-builder-v9' ) : esc_html__( 'Compatibility Safe Mode disabled.', 'delicat-builder-v9' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( isset( $_GET['integrity'] ) ) : ?>
				<?php $integrity_notice = sanitize_key( wp_unslash( $_GET['integrity'] ) ); ?>
				<div class="notice <?php echo 'ok' === $integrity_notice ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo 'ok' === $integrity_notice ? esc_html__( 'Builder integrity scan passed.', 'delicat-builder-v9' ) : esc_html__( 'Builder integrity scan found changed or missing plugin files. Review before production.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-admin__grid">
				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'Production policy', 'delicat-builder-v9' ); ?></h2>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="delicat_builder_v9_production_save">
						<?php wp_nonce_field( 'delicat_builder_v9_production_save' ); ?>

						<label class="delicat-switch">
							<input type="checkbox" name="production[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable production hardening', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Independent production-hardening switch. OFF by default.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="production[private_cache_headers]" value="1" <?php checked( ! empty( $s['private_cache_headers'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Private no-store headers on sensitive pages', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Applies only when no stricter Cache-Control is already present; Cart, Checkout, My Account/login/API/callback contexts are treated as sensitive.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="production[csp_report_only]" value="1" <?php checked( ! empty( $s['csp_report_only'] ) ); ?>>
							<span><strong><?php esc_html_e( 'CSP Report-Only monitor', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Never enforced by Delicat Builder RC 36. Skips sensitive commerce/login/API pages and does not create a report endpoint.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'CSP monitoring profile', 'delicat-builder-v9' ); ?></strong></label>
							<select name="production[csp_profile]">
								<option value="compatibility" <?php selected( $s['csp_profile'], 'compatibility' ); ?>><?php esc_html_e( 'Compatibility monitor — recommended first', 'delicat-builder-v9' ); ?></option>
								<option value="strict-monitor" <?php selected( $s['csp_profile'], 'strict-monitor' ); ?>><?php esc_html_e( 'Strict monitor — expect more console violations', 'delicat-builder-v9' ); ?></option>
							</select>
							<small><?php esc_html_e( 'Report-Only violations can be reviewed in browser developer tools. RC 36 does not transmit violation reports to Delicat or a third party.', 'delicat-builder-v9' ); ?></small>
						</div>

						<div class="delicat-field">
							<label><strong><?php esc_html_e( 'Integrity result cache', 'delicat-builder-v9' ); ?></strong></label>
							<input type="number" min="5" max="60" name="production[integrity_scan_cache_min]" value="<?php echo esc_attr( (string) $s['integrity_scan_cache_min'] ); ?>">
							<small><?php esc_html_e( 'Minutes. Integrity hashing runs in admin diagnostics only, not on normal visitor requests.', 'delicat-builder-v9' ); ?></small>
						</div>

						<?php submit_button( __( 'Save production policy', 'delicat-builder-v9' ) ); ?>
					</form>
				</section>

				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'Release diagnostics', 'delicat-builder-v9' ); ?></h2>
					<div class="delicat-status-list">
						<?php foreach ( $diagnostics as $item ) : ?>
							<div class="delicat-status">
								<span class="delicat-status__dot <?php echo ! empty( $item['ok'] ) ? 'is-ok' : 'is-warn'; ?>"></span>
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span><?php echo esc_html( $item['text'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<p>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_integrity_scan' ), 'delicat_builder_v9_integrity_scan' ) ); ?>"><?php esc_html_e( 'Run integrity scan now', 'delicat-builder-v9' ); ?></a>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_export_config' ), 'delicat_builder_v9_export_config' ) ); ?>"><?php esc_html_e( 'Download safe configuration snapshot', 'delicat-builder-v9' ); ?></a>
					</p>

					<?php if ( ! empty( $integrity['modified'] ) || ! empty( $integrity['missing'] ) ) : ?>
						<div class="delicat-warning">
							<strong><?php esc_html_e( 'Integrity warning', 'delicat-builder-v9' ); ?></strong>
							<p><?php echo esc_html( sprintf( __( '%1$d modified and %2$d missing plugin file(s) detected.', 'delicat-builder-v9' ), count( $integrity['modified'] ), count( $integrity['missing'] ) ) ); ?></p>
						</div>
					<?php endif; ?>
				</section>
			</div>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Emergency Compatibility Safe Mode', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Safe Mode keeps Builder content/layout data intact and suppresses the riskier application layers. In RC39.11, the mobile commerce bar, read-only product search, and narrowly scoped native Woo template previews can remain available while riskier mini-cart, Identity and full shell integrations stay quarantined.', 'delicat-builder-v9' ); ?></p>
				<p><strong><?php echo $safe_mode ? esc_html__( 'CURRENTLY ACTIVE', 'delicat-builder-v9' ) : esc_html__( 'Currently off', 'delicat-builder-v9' ); ?></strong></p>
				<?php if ( $safe_mode ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delicat_builder_v9_safe_mode', 'enable' => 0 ), admin_url( 'admin-post.php' ) ), 'delicat_builder_v9_safe_mode' ) ); ?>"><?php esc_html_e( 'Disable Safe Mode', 'delicat-builder-v9' ); ?></a>
				<?php else : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delicat_builder_v9_safe_mode', 'enable' => 1 ), admin_url( 'admin-post.php' ) ), 'delicat_builder_v9_safe_mode' ) ); ?>"><?php esc_html_e( 'Enable emergency Safe Mode', 'delicat-builder-v9' ); ?></a>
				<?php endif; ?>
				<?php if ( ! empty( $last_fatal ) ) : ?>
					<div class="delicat-warning" style="margin-top:16px">
						<strong><?php esc_html_e( 'Last Builder fatal captured', 'delicat-builder-v9' ); ?></strong>
						<p><?php echo esc_html( sprintf( 'File: %1$s · line %2$d · %3$s', (string) ( $last_fatal['file'] ?? 'unknown' ), absint( $last_fatal['line'] ?? 0 ), (string) ( $last_fatal['time'] ?? '' ) ) ); ?></p>
						<p><?php echo esc_html( sprintf( 'Cause: %1$s · PHP %2$s · WordPress %3$s', (string) ( $last_fatal['kind'] ?? 'legacy/unknown' ), (string) ( $last_fatal['php_version'] ?? PHP_VERSION ), (string) ( $last_fatal['wp_version'] ?? get_bloginfo( 'version' ) ) ) ); ?></p>
						<p><code><?php echo esc_html( substr( (string) ( $last_fatal['hash'] ?? '' ), 0, 20 ) ); ?></code></p>
					</div>
				<?php endif; ?>
				<?php $dependency_gate = get_option( 'delicat_builder_v9_dependency_gate', array() ); ?>
				<?php if ( is_array( $dependency_gate ) && ! empty( $dependency_gate['missing'] ) ) : ?>
					<div class="delicat-warning" style="margin-top:16px">
						<strong><?php esc_html_e( 'RC39.13 dependency gate — Builder dormant', 'delicat-builder-v9' ); ?></strong>
						<p><?php echo esc_html( sprintf( 'A critical module failed to load (%1$s · context: %2$s). The Builder stayed dormant so the storefront falls back to plain WooCommerce instead of fataling.', (string) ( $dependency_gate['time'] ?? '' ), (string) ( $dependency_gate['context'] ?? '' ) ) ); ?></p>
						<p><code><?php echo esc_html( implode( ', ', array_map( 'strval', (array) $dependency_gate['missing'] ) ) ); ?></code></p>
						<p><?php esc_html_e( 'Re-upload the plugin files (see Module load failures below for the broken file), then reload — the gate clears automatically once every critical module loads.', 'delicat-builder-v9' ); ?></p>
					</div>
				<?php endif; ?>
				<?php $template_failure = get_option( 'delicat_builder_v9_last_template_failure', array() ); ?>
				<?php if ( is_array( $template_failure ) && ! empty( $template_failure ) ) : ?>
					<div class="delicat-warning" style="margin-top:16px">
						<strong><?php esc_html_e( 'Last native Woo template fallback', 'delicat-builder-v9' ); ?></strong>
						<p><?php echo esc_html( sprintf( 'Context: %1$s · file %2$s · line %3$d · %4$s', (string) ( $template_failure['context'] ?? 'unknown' ), (string) ( $template_failure['file'] ?? 'unknown' ), absint( $template_failure['line'] ?? 0 ), (string) ( $template_failure['time'] ?? '' ) ) ); ?></p>
						<p><code><?php echo esc_html( substr( (string) ( $template_failure['hash'] ?? '' ), 0, 20 ) ); ?></code></p>
					</div>
				<?php endif; ?>
				<?php $module_failures = get_option( 'delicat_builder_v9_module_load_failures', array() ); ?>
				<?php if ( is_array( $module_failures ) && ! empty( $module_failures ) ) : $module_failure = end( $module_failures ); ?>
					<div class="delicat-warning" style="margin-top:16px">
						<strong><?php esc_html_e( 'Last guarded module load failure', 'delicat-builder-v9' ); ?></strong>
						<p><?php echo esc_html( sprintf( 'File: %1$s · line %2$d · %3$s', (string) ( $module_failure['file'] ?? 'unknown' ), absint( $module_failure['line'] ?? 0 ), (string) ( $module_failure['time'] ?? '' ) ) ); ?></p>
						<p><code><?php echo esc_html( substr( (string) ( $module_failure['hash'] ?? '' ), 0, 20 ) ); ?></code></p>
					</div>
				<?php endif; ?>
			</section>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'What RC 39.7 deliberately does not automate', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'HSTS, enforced CSP, COOP/COEP, third-party preconnects and wp-config.php edits are intentionally not enabled automatically. Those can break OAuth, payment iframes, CDNs or subdomains and should be introduced only after staging/network validation.', 'delicat-builder-v9' ); ?></p>
			</section>
		</div>
		<?php
	}
}
