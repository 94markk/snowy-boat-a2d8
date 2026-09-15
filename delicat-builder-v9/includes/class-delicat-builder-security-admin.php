<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Security_Admin {
	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 24 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_delicat_builder_v9_security_sync_save', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Security & Identity Sync', 'delicat-builder-v9' ),
			__( 'Security Studio', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-security',
			array( __CLASS__, 'page' )
		);
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-security' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	public static function save(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}

		if ( ! Delicat_Builder_V9_Identity_Bridge::can_manage_global_security() ) {
			wp_die( esc_html__( 'You are not allowed to change Builder security synchronization.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'delicat_builder_v9_security_sync_save' );

		Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change();

		$input = isset( $_POST['identity_sync'] ) && is_array( $_POST['identity_sync'] )
			? wp_unslash( $_POST['identity_sync'] )
			: array();

		$clean = array(
			'identity_authority'   => empty( $input['identity_authority'] ) ? 0 : 1,
			'shell_login_modal'    => empty( $input['shell_login_modal'] ) ? 0 : 1,
			'audit_bridge'         => empty( $input['audit_bridge'] ) ? 0 : 1,
			'admin_guard_bridge'   => empty( $input['admin_guard_bridge'] ) ? 0 : 1,
			'reauth_security_save' => empty( $input['reauth_security_save'] ) ? 0 : 1,
		);

		update_option( Delicat_Builder_V9_Identity_Bridge::OPTION, $clean, false );

		/* 9.1 RC10: security-sync settings shape frontend headers/markup that
		 * LiteSpeed/Cloudflare may hold; refresh the cache generation on save. */
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		do_action( 'litespeed_purge_all' );
		Delicat_Builder_V9_Identity_Bridge::reset_settings_cache();

		Delicat_Builder_V9_Identity_Bridge::audit(
			'builder_identity_sync_updated',
			'warning',
			array(
				'authority'   => $clean['identity_authority'],
				'login_modal' => $clean['shell_login_modal'],
				'audit'       => $clean['audit_bridge'],
				'admin_guard' => $clean['admin_guard_bridge'],
				'reauth'      => $clean['reauth_security_save'],
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'delicat-builder-v9-security',
					'saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function page(): void {
		if ( ! Delicat_Builder_V9_Identity_Bridge::can_manage_global_security() ) {
			wp_die( esc_html__( 'You cannot manage Builder security.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}

		$s = Delicat_Builder_V9_Identity_Bridge::settings();
		$health = Delicat_Builder_V9_Identity_Bridge::security_health();
		$identity_url = admin_url( 'options-general.php?page=delicat-identity' );
		$security_center_url = admin_url( 'options-general.php?page=dip-security-center' );
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">IDENTITY SECURITY AUTHORITY · PRESERVED IN RC 36</span>
					<h1><?php esc_html_e( 'Security Studio', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Builder does not implement a competing login, 2FA or passkey system. Delicat Identity Pro is the security authority whenever it is active.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Identity synchronization saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! Delicat_Builder_V9_Identity_Bridge::identity_available() ) : ?>
				<div class="delicat-warning">
					<strong><?php esc_html_e( 'Identity Pro not detected', 'delicat-builder-v9' ); ?></strong>
					<p><?php esc_html_e( 'Builder will fall back to normal WordPress/WooCommerce account behavior. Install/activate Delicat Identity Pro to restore unified modal login, 2FA, passkeys, audit and security guard synchronization.', 'delicat-builder-v9' ); ?></p>
				</div>
			<?php elseif ( ! Delicat_Builder_V9_Identity_Bridge::compatible() ) : ?>
				<div class="delicat-warning">
					<strong><?php esc_html_e( 'Identity compatibility warning', 'delicat-builder-v9' ); ?></strong>
					<p><?php echo esc_html( sprintf( __( 'Detected Identity Pro %s. This Builder integration was verified against Identity Pro 6.9.8 SDK API 1.0.', 'delicat-builder-v9' ), Delicat_Builder_V9_Identity_Bridge::identity_version() ) ); ?></p>
				</div>
			<?php endif; ?>

			<div class="delicat-admin__grid">
				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'Identity Pro health', 'delicat-builder-v9' ); ?></h2>
					<div class="delicat-status-list">
						<?php foreach ( $health as $item ) : ?>
							<div class="delicat-status">
								<span class="delicat-status__dot <?php echo ! empty( $item['ok'] ) ? 'is-ok' : 'is-warn'; ?>"></span>
								<strong><?php echo esc_html( $item['label'] ); ?></strong>
								<span><?php echo esc_html( $item['text'] ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>

					<?php if ( Delicat_Builder_V9_Identity_Bridge::identity_available() ) : ?>
						<p>
							<a class="button button-primary" href="<?php echo esc_url( $identity_url ); ?>"><?php esc_html_e( 'Open Identity Pro', 'delicat-builder-v9' ); ?></a>
							<a class="button" href="<?php echo esc_url( $security_center_url ); ?>"><?php esc_html_e( 'Open Identity Security Center', 'delicat-builder-v9' ); ?></a>
						</p>
					<?php endif; ?>
				</section>

				<section class="delicat-admin__card">
					<h2><?php esc_html_e( 'Synchronization policy', 'delicat-builder-v9' ); ?></h2>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="delicat_builder_v9_security_sync_save">
						<?php wp_nonce_field( 'delicat_builder_v9_security_sync_save' ); ?>

						<label class="delicat-switch">
							<input type="checkbox" name="identity_sync[identity_authority]" value="1" <?php checked( ! empty( $s['identity_authority'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Identity Pro is authentication authority', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'No duplicate Builder login, 2FA, passkey or device security engine.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="identity_sync[shell_login_modal]" value="1" <?php checked( ! empty( $s['shell_login_modal'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Use Identity modal from Builder header', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Logged-out account button opens Identity Pro login/register modal and uses Identity rate limits, Google login and 2FA flow.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="identity_sync[audit_bridge]" value="1" <?php checked( ! empty( $s['audit_bridge'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Send Builder security events to Identity audit', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Only sanitized event metadata is sent; passwords, tokens, e-mail, IP, secrets and nonces are excluded.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="identity_sync[admin_guard_bridge]" value="1" <?php checked( ! empty( $s['admin_guard_bridge'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Identity Access Guard for global Builder settings', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Defense-in-depth for Builder dashboards/global maintenance. Page Builder editor keeps edit_pages/edit_post permissions.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<label class="delicat-switch">
							<input type="checkbox" name="identity_sync[reauth_security_save]" value="1" <?php checked( ! empty( $s['reauth_security_save'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Require recent Identity confirmation for security-policy changes', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Uses Identity Pro reauthentication rather than a second Builder password/2FA challenge.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<?php submit_button( __( 'Save synchronized security policy', 'delicat-builder-v9' ), 'primary', 'submit', false ); ?>
					</form>
				</section>
			</div>

			<section class="delicat-admin__card" style="margin-top:20px">
				<h2><?php esc_html_e( 'Authority map', 'delicat-builder-v9' ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Area', 'delicat-builder-v9' ); ?></th><th><?php esc_html_e( 'Authority', 'delicat-builder-v9' ); ?></th></tr></thead>
					<tbody>
						<tr><td>Login / registration</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Google / Microsoft authentication</td><td>Delicat Identity Pro</td></tr>
						<tr><td>TOTP / recovery codes</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Passkeys / WebAuthn</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Device/session security</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Admin Google Secure Mode</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Brute-force / lockouts</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Authentication security audit</td><td>Delicat Identity Pro</td></tr>
						<tr><td>Builder layout / compiler / performance</td><td>Delicat Builder V9</td></tr>
						<tr><td>Products / cart / checkout / order validation</td><td>WooCommerce</td></tr>
					</tbody>
				</table>
			</section>
		</div>
		<?php
	}
}
