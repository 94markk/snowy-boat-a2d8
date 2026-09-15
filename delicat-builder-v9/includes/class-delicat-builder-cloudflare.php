<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.32 — Cloudflare Turbo module.
 *
 * Purpose: once Cloudflare edge-caches guest HTML ("Cache Everything" rule),
 * every content change must purge Cloudflare too, or visitors keep seeing the
 * old page for hours. V9 already signals LiteSpeed through the
 * `litespeed_purge_url` / `litespeed_purge_post` / `litespeed_purge_all`
 * actions (review approvals, layout saves, Release Center, etc.). This module
 * listens to those same actions, collects the affected URLs during the
 * request, de-duplicates them, and sends ONE batched purge call to the
 * Cloudflare API on shutdown. No WP-Cron, no new frontend assets, zero cost
 * when disabled.
 *
 * Security: the API token is write-only in the admin UI (never echoed back),
 * should be scoped to "Zone → Cache Purge" only, and all admin actions are
 * capability + nonce gated. PHP 8.5.
 */
final class Delicat_Builder_V9_Cloudflare {

	const OPTION     = 'delicat_builder_v9_cloudflare';
	const STATUS_OPT = 'delicat_builder_v9_cloudflare_status';
	const API_BASE   = 'https://api.cloudflare.com/client/v4/zones/';
	const MAX_URLS   = 30; // Cloudflare accepts up to 30 files per purge call.

	/** @var array<string,bool> */
	private static $queued_urls = array();
	private static $purge_all   = false;
	private static $hooked_shutdown = false;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'migrate_token' ), 30 );
		if ( self::enabled() ) {
			add_action( 'litespeed_purge_url', array( __CLASS__, 'queue_url' ) );
			add_action( 'litespeed_purge_post', array( __CLASS__, 'queue_post' ) );
			add_action( 'litespeed_purge_all', array( __CLASS__, 'queue_all' ) );
		}

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
			add_action( 'admin_post_delicat_builder_v9_cf_save', array( __CLASS__, 'handle_save' ) );
			add_action( 'admin_post_delicat_builder_v9_cf_test', array( __CLASS__, 'handle_test' ) );
			add_action( 'admin_post_delicat_builder_v9_cf_purge_all', array( __CLASS__, 'handle_purge_all' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$token = '';
		if ( ! empty( $saved['api_token_encrypted'] ) && function_exists( 'delicat_builder_v9_open_sensitive_value' ) ) {
			$token = delicat_builder_v9_open_sensitive_value( $saved['api_token_encrypted'] );
		} elseif ( empty( $saved['api_token_encrypted'] ) ) {
			$token = (string) ( $saved['api_token'] ?? '' );
		}
		return array(
			'enabled'   => ! empty( $saved['enabled'] ),
			'zone_id'   => (string) ( $saved['zone_id'] ?? '' ),
			'api_token' => $token,
		);
	}

	/** Encrypt an existing credential without rotating the token or WP salts. */
	public static function migrate_token(): void {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) || empty( $saved['api_token'] ) || ! function_exists( 'delicat_builder_v9_seal_sensitive_value' ) ) { return; }
		if ( ! empty( $saved['api_token_encrypted'] ) ) {
			// An unreadable encrypted credential requires operator recovery.
			return;
		}
		$sealed = delicat_builder_v9_seal_sensitive_value( $saved['api_token'] );
		if ( '' === $sealed ) { return; }
		$saved['api_token_encrypted'] = $sealed;
		unset( $saved['api_token'] );
		update_option( self::OPTION, $saved, false );
	}

	public static function enabled(): bool {
		$s = self::settings();
		return $s['enabled'] && '' !== $s['zone_id'] && '' !== $s['api_token'];
	}

	/* ------------------------------------------------------------------ */
	/* Purge queue                                                         */
	/* ------------------------------------------------------------------ */

	public static function queue_url( $url ): void {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return;
		}
		self::$queued_urls[ $url ] = true;
		/* The bare URL and its trailing-slash twin are distinct edge cache keys. */
		$twin = '/' === substr( $url, -1 ) ? rtrim( $url, '/' ) : $url . '/';
		if ( $twin !== $url && false === strpos( $twin, '?' ) ) {
			self::$queued_urls[ $twin ] = true;
		}
		self::hook_shutdown();
	}

	public static function queue_post( $post_id ): void {
		$url = get_permalink( absint( $post_id ) );
		if ( is_string( $url ) && '' !== $url ) {
			self::queue_url( $url );
		}
	}

	public static function queue_all(): void {
		self::$purge_all = true;
		self::hook_shutdown();
	}

	private static function hook_shutdown(): void {
		if ( self::$hooked_shutdown ) {
			return;
		}
		self::$hooked_shutdown = true;
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 90 );
	}

	/** Sends at most two API calls per request: one purge-all OR batched files. */
	public static function flush(): void {
		if ( ! self::enabled() ) {
			return;
		}

		if ( self::$purge_all ) {
			self::api_purge( array( 'purge_everything' => true ) );
			self::$purge_all   = false;
			self::$queued_urls = array();
			return;
		}

		if ( empty( self::$queued_urls ) ) {
			return;
		}
		$files = array_slice( array_keys( self::$queued_urls ), 0, self::MAX_URLS );
		self::$queued_urls = array();
		self::api_purge( array( 'files' => $files ) );
	}

	/* ------------------------------------------------------------------ */
	/* Cloudflare API                                                      */
	/* ------------------------------------------------------------------ */

	private static function api_purge( array $body ): bool {
		$s = self::settings();
		$response = wp_safe_remote_post(
			self::API_BASE . rawurlencode( $s['zone_id'] ) . '/purge_cache',
			array(
				'timeout' => 4,
				'sslverify' => true,
				'redirection' => 0,
				'limit_response_size' => 131072,
				'headers' => array(
					'Authorization' => 'Bearer ' . $s['api_token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$ok   = false;
		$note = '';
		if ( is_wp_error( $response ) ) {
			$note = sanitize_text_field( $response->get_error_message() );
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$ok   = 200 === $code && is_array( $data ) && ! empty( $data['success'] );
			if ( ! $ok ) {
				$err  = is_array( $data ) && ! empty( $data['errors'][0]['message'] ) ? (string) $data['errors'][0]['message'] : ( 'HTTP ' . $code );
				$note = sanitize_text_field( $err );
			}
		}

		update_option(
			self::STATUS_OPT,
			array(
				'time'  => gmdate( 'c' ),
				'ok'    => $ok ? 1 : 0,
				'note'  => $note,
				'scope' => ! empty( $body['purge_everything'] ) ? 'all' : 'urls:' . count( (array) ( $body['files'] ?? array() ) ),
			),
			false
		);
		return $ok;
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Cloudflare Turbo', 'delicat-builder-v9' ),
			__( 'Cloudflare Turbo', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-cloudflare',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_cf' );

		$current = self::settings();
		$token   = isset( $_POST['api_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) ) : '';
		$next    = array(
			'enabled'   => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'zone_id'   => isset( $_POST['zone_id'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) wp_unslash( $_POST['zone_id'] ) ) : '',
			/* Blank token field keeps the stored token (it is never echoed back). */
			'api_token' => '' !== $token ? $token : $current['api_token'],
		);
		$stored = get_option( self::OPTION, array() );
		$preserve = '' === $token && is_array( $stored ) && ! empty( $stored['api_token_encrypted'] );
		$plain = $next['api_token'];
		unset( $next['api_token'] );
		$next['api_token_encrypted'] = '' !== $plain && function_exists( 'delicat_builder_v9_seal_sensitive_value' ) ? delicat_builder_v9_seal_sensitive_value( $plain ) : '';
		if ( $preserve ) { $next['api_token_encrypted'] = $stored['api_token_encrypted']; }
		if ( '' !== $plain && '' === $next['api_token_encrypted'] ) {
			wp_die( 'Chiffrement indisponible. Aucun token enregistré.', 'Encryption required', array( 'response' => 503 ) );
		}
		update_option( self::OPTION, $next, false );
		self::redirect_back( 'saved' );
	}

	public static function handle_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_cf' );
		/* A purge of a harmless URL validates zone + token with a purge-only scope. */
		$ok = self::api_purge( array( 'files' => array( home_url( '/?dlc-cf-test=1' ) ) ) );
		self::redirect_back( $ok ? 'test_ok' : 'test_fail' );
	}

	public static function handle_purge_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_cf' );
		$ok = self::api_purge( array( 'purge_everything' => true ) );
		self::redirect_back( $ok ? 'purged' : 'purge_fail' );
	}

	private static function redirect_back( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'delicat-builder-v9-cloudflare',
					'cf_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = self::settings();
		$status = get_option( self::STATUS_OPT, array() );
		$status = is_array( $status ) ? $status : array();
		$notice = isset( $_GET['cf_notice'] ) ? sanitize_key( wp_unslash( $_GET['cf_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$messages = array(
			'saved'      => array( 'success', __( 'Réglages Cloudflare enregistrés.', 'delicat-builder-v9' ) ),
			'test_ok'    => array( 'success', __( 'Connexion Cloudflare réussie ✔ Le token et la Zone ID sont valides.', 'delicat-builder-v9' ) ),
			'test_fail'  => array( 'error', __( 'Échec de connexion Cloudflare — vérifie la Zone ID et le token (portée requise : Zone → Cache Purge).', 'delicat-builder-v9' ) ),
			'purged'     => array( 'success', __( 'Cache Cloudflare entièrement purgé.', 'delicat-builder-v9' ) ),
			'purge_fail' => array( 'error', __( 'La purge Cloudflare a échoué — voir le dernier statut ci-dessous.', 'delicat-builder-v9' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cloudflare Turbo — purge automatique du cache edge', 'delicat-builder-v9' ); ?></h1>
			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $messages[ $notice ][0] ); ?> is-dismissible"><p><?php echo esc_html( $messages[ $notice ][1] ); ?></p></div>
			<?php endif; ?>
			<p style="max-width:720px">
				<?php esc_html_e( 'Quand la règle « Cache Everything » est active côté Cloudflare, ce module purge automatiquement les mêmes URLs que LiteSpeed à chaque changement (avis approuvé, page sauvegardée, purge manuelle du Builder). Une seule requête API groupée par changement.', 'delicat-builder-v9' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px">
				<?php wp_nonce_field( 'delicat_builder_v9_cf' ); ?>
				<input type="hidden" name="action" value="delicat_builder_v9_cf_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activer', 'delicat-builder-v9' ); ?></th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Purger Cloudflare automatiquement avec LiteSpeed', 'delicat-builder-v9' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="dlc-cf-zone"><?php esc_html_e( 'Zone ID', 'delicat-builder-v9' ); ?></label></th>
						<td>
							<input type="text" id="dlc-cf-zone" name="zone_id" class="regular-text code" value="<?php echo esc_attr( $s['zone_id'] ); ?>" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Tableau de bord Cloudflare → ton domaine → Overview → colonne de droite « Zone ID ».', 'delicat-builder-v9' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dlc-cf-token"><?php esc_html_e( 'API Token', 'delicat-builder-v9' ); ?></label></th>
						<td>
							<input type="password" id="dlc-cf-token" name="api_token" class="regular-text code" value="" placeholder="<?php echo esc_attr( '' !== $s['api_token'] ? __( '•••••••• (token enregistré — laisser vide pour conserver)', 'delicat-builder-v9' ) : '' ); ?>" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Crée un token avec la SEULE permission « Zone → Cache Purge → Purge » limité à ta zone. Le token n’est jamais réaffiché.', 'delicat-builder-v9' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Enregistrer', 'delicat-builder-v9' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Actions', 'delicat-builder-v9' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
				<?php wp_nonce_field( 'delicat_builder_v9_cf' ); ?>
				<input type="hidden" name="action" value="delicat_builder_v9_cf_test">
				<?php submit_button( __( 'Tester la connexion', 'delicat-builder-v9' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<?php wp_nonce_field( 'delicat_builder_v9_cf' ); ?>
				<input type="hidden" name="action" value="delicat_builder_v9_cf_purge_all">
				<?php submit_button( __( 'Purger tout Cloudflare maintenant', 'delicat-builder-v9' ), 'delete', 'submit', false ); ?>
			</form>

			<?php if ( ! empty( $status ) ) : ?>
				<h2><?php esc_html_e( 'Dernière purge', 'delicat-builder-v9' ); ?></h2>
				<p>
					<?php echo ! empty( $status['ok'] ) ? '✅' : '❌'; ?>
					<code><?php echo esc_html( (string) ( $status['scope'] ?? '' ) ); ?></code>
					— <?php echo esc_html( (string) ( $status['time'] ?? '' ) ); ?>
					<?php if ( '' !== (string) ( $status['note'] ?? '' ) ) : ?>
						· <em><?php echo esc_html( (string) $status['note'] ); ?></em>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
