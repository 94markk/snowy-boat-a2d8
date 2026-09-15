<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintenance Studio admin (RC40).
 *
 * Settings, the template gallery, a real preview of the closed store, and a
 * permanent reminder in wp-admin while the site is closed — an administrator
 * should never be able to forget that customers are looking at a 503.
 */
final class Delicat_Builder_V9_Maintenance_Admin {
	public const PAGE = 'delicat-builder-v9-maintenance';

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 33 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_delicat_builder_v9_maintenance_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'admin_post_delicat_builder_v9_maintenance_rotate', array( __CLASS__, 'handle_rotate' ) );
		add_action( 'template_redirect', array( __CLASS__, 'preview' ), 1 );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Maintenance', 'delicat-builder-v9' ),
			__( 'Maintenance', 'delicat-builder-v9' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_maintenance',
			Delicat_Builder_V9_Maintenance::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Maintenance::defaults(),
			)
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = Delicat_Builder_V9_Maintenance::settings();

		$template = sanitize_key( (string) ( $input['template'] ?? 'aurora' ) );
		if ( ! in_array( $template, Delicat_Builder_V9_Maintenance::TEMPLATES, true ) ) {
			$template = 'aurora';
		}

		$roles = isset( $input['allowed_roles'] ) && is_array( $input['allowed_roles'] )
			? array_values( array_filter( array_map( 'sanitize_key', $input['allowed_roles'] ) ) )
			: array();
		if ( ! in_array( 'administrator', $roles, true ) ) {
			$roles[] = 'administrator';
		}

		$enabled = empty( $input['enabled'] ) ? 0 : 1;

		/* The start time anchors the progress bar. It is stamped the moment
		 * maintenance is switched on, never overwritten while it stays on. */
		$start = sanitize_text_field( (string) ( $previous['start_time'] ?? '' ) );
		if ( $enabled && ( empty( $previous['enabled'] ) || '' === $start ) ) {
			$start = current_time( 'Y-m-d\TH:i' );
		}
		if ( ! $enabled ) {
			$start = '';
		}

		$end = self::sanitize_datetime( (string) ( $input['end_time'] ?? '' ) );
		/* Enabling with a return time already past would schedule an immediate
		 * reopen: the store would look like it refuses to close. */
		if ( $enabled && '' !== $end && Delicat_Builder_V9_Maintenance::timestamp( $end ) <= time() ) {
			$end = '';
		}

		$key = preg_replace( '/[^a-f0-9]/i', '', (string) ( $previous['bypass_key'] ?? '' ) );

		$clean = array(
			'enabled'                 => $enabled,
			'template'                => $template,
			'eyebrow'                 => sanitize_text_field( (string) ( $input['eyebrow'] ?? '' ) ),
			'title'                   => sanitize_text_field( (string) ( $input['title'] ?? '' ) ),
			'message'                 => sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ),
			'show_logo'               => empty( $input['show_logo'] ) ? 0 : 1,
			'logo_url'                => esc_url_raw( (string) ( $input['logo_url'] ?? '' ) ),
			'show_brand'              => empty( $input['show_brand'] ) ? 0 : 1,
			'brand_text'              => sanitize_text_field( (string) ( $input['brand_text'] ?? '' ) ),
			'notices'                 => sanitize_textarea_field( (string) ( $input['notices'] ?? '' ) ),
			'footer_text'             => sanitize_text_field( (string) ( $input['footer_text'] ?? '' ) ),
			'countdown'               => empty( $input['countdown'] ) ? 0 : 1,
			'progress'                => empty( $input['progress'] ) ? 0 : 1,
			'start_time'              => $start,
			'end_time'                => $end,
			'auto_end'                => empty( $input['auto_end'] ) ? 0 : 1,
			'accent'                  => Delicat_Builder_V9_Maintenance::hex( (string) ( $input['accent'] ?? '' ), '#ff5a1f' ),
			'bg_start'                => Delicat_Builder_V9_Maintenance::hex( (string) ( $input['bg_start'] ?? '' ), '#0d1b4d' ),
			'bg_end'                  => Delicat_Builder_V9_Maintenance::hex( (string) ( $input['bg_end'] ?? '' ), '#284696' ),
			'whatsapp'                => sanitize_text_field( (string) ( $input['whatsapp'] ?? '' ) ),
			'email'                   => sanitize_email( (string) ( $input['email'] ?? '' ) ),
			'phone'                   => sanitize_text_field( (string) ( $input['phone'] ?? '' ) ),
			'facebook'                => esc_url_raw( (string) ( $input['facebook'] ?? '' ) ),
			'instagram'               => esc_url_raw( (string) ( $input['instagram'] ?? '' ) ),
			'tiktok'                  => esc_url_raw( (string) ( $input['tiktok'] ?? '' ) ),
			'show_admin_link'         => empty( $input['show_admin_link'] ) ? 0 : 1,
			'retry_after'             => min( DAY_IN_SECONDS, max( 60, absint( $input['retry_after'] ?? 3600 ) ) ),
			'allowed_roles'           => $roles,
			'allow_ips'               => sanitize_textarea_field( (string) ( $input['allow_ips'] ?? '' ) ),
			'bypass_key'              => $key,
			'lock_api'                => empty( $input['lock_api'] ) ? 0 : 1,
			'allow_payment_callbacks' => empty( $input['allow_payment_callbacks'] ) ? 0 : 1,
		);

		Delicat_Builder_V9_Maintenance::reset_settings_cache();
		Delicat_Builder_V9_Maintenance::purge_caches();

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				$enabled ? 'builder_maintenance_enabled' : 'builder_maintenance_disabled',
				$enabled ? 'warning' : 'notice'
			);
		}

		return $clean;
	}

	/** Accepts only what an `<input type="datetime-local">` can produce. */
	private static function sanitize_datetime( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $value ) ) {
			return '';
		}
		return substr( $value, 0, 16 );
	}

	/* ------------------------------------------------------------------ */
	/* One-click controls                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Sets an explicit state, never a toggle.
	 *
	 * A toggling button reads its meaning from a page that may already be
	 * stale: clicking it twice — for instance after checking the storefront,
	 * which still looks open to an administrator — silently reopens the store.
	 * The requested state travels in the link, so repeating a click is a no-op.
	 */
	public static function handle_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change maintenance mode.', 'delicat-builder-v9' ) );
		}
		check_admin_referer( 'delicat_builder_v9_maintenance_toggle' );

		$state = isset( $_GET['state'] ) ? absint( wp_unslash( $_GET['state'] ) ) : 0;
		$now   = Delicat_Builder_V9_Maintenance::set_enabled( 1 === $state );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&result=' . ( $now ? 'closed' : 'open' ) ) );
		exit;
	}

	public static function handle_rotate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change maintenance mode.', 'delicat-builder-v9' ) );
		}
		check_admin_referer( 'delicat_builder_v9_maintenance_rotate' );

		$saved = get_option( Delicat_Builder_V9_Maintenance::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : Delicat_Builder_V9_Maintenance::defaults();
		$saved['bypass_key'] = '';
		update_option( Delicat_Builder_V9_Maintenance::OPTION, $saved, true );
		Delicat_Builder_V9_Maintenance::reset_settings_cache();
		Delicat_Builder_V9_Maintenance::bypass_key();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&rotated=1' ) );
		exit;
	}

	/**
	 * Administrator preview of the closed store, on any template, whether or
	 * not maintenance is currently on. Capability + nonce, never a 503.
	 */
	public static function preview(): void {
		if ( empty( $_GET['delicat_maintenance_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ), 'delicat_builder_v9_maintenance_preview' ) ) {
			return;
		}

		$template = isset( $_GET['tpl'] ) ? sanitize_key( wp_unslash( (string) $_GET['tpl'] ) ) : '';
		Delicat_Builder_V9_Maintenance::render( true, $template );
	}

	public static function preview_url( string $template = '' ): string {
		$url = add_query_arg( 'delicat_maintenance_preview', '1', home_url( '/' ) );
		if ( '' !== $template ) {
			$url = add_query_arg( 'tpl', $template, $url );
		}
		return wp_nonce_url( $url, 'delicat_builder_v9_maintenance_preview' );
	}

	/* ------------------------------------------------------------------ */
	/* Reminders                                                           */
	/* ------------------------------------------------------------------ */

	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! Delicat_Builder_V9_Maintenance::is_enabled() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->id ) && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Mode maintenance actif.', 'delicat-builder-v9' ); ?></strong>
				<?php esc_html_e( 'La boutique est fermée : seuls les administrateurs voient le site, tous les autres visiteurs reçoivent la page de maintenance (503).', 'delicat-builder-v9' ); ?>
				<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_maintenance_toggle&state=0' ), 'delicat_builder_v9_maintenance_toggle' ) ); ?>"><?php esc_html_e( 'Rouvrir la boutique', 'delicat-builder-v9' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Réglages', 'delicat-builder-v9' ); ?></a>
			</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	private static function template_cards(): array {
		return array(
			'flash'     => array( __( 'Flash', 'delicat-builder-v9' ), __( 'Scène sombre, carte au contour accentué, nom de la boutique en deux tons et titre en gras. Le plus proche d’une app.', 'delicat-builder-v9' ) ),
			'aurora'    => array( __( 'Aurora', 'delicat-builder-v9' ), __( 'Dégradé coloré animé, carte claire. Le choix par défaut, lisible sur mobile.', 'delicat-builder-v9' ) ),
			'midnight'  => array( __( 'Midnight', 'delicat-builder-v9' ), __( 'Fond marine profond aux couleurs Délicat, compte à rebours large.', 'delicat-builder-v9' ) ),
			'minimal'   => array( __( 'Minimal', 'delicat-builder-v9' ), __( 'Blanc éditorial, typographie d’abord, aucune animation de fond.', 'delicat-builder-v9' ) ),
			'boutique'  => array( __( 'Boutique', 'delicat-builder-v9' ), __( 'Panneau de marque et zone d’informations séparés, esprit commerce.', 'delicat-builder-v9' ) ),
			'spotlight' => array( __( 'Spotlight', 'delicat-builder-v9' ), __( 'Scène sombre, faisceau lumineux centré, très calme.', 'delicat-builder-v9' ) ),
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s      = Delicat_Builder_V9_Maintenance::settings();
		$option = Delicat_Builder_V9_Maintenance::OPTION;
		$on     = ! empty( $s['enabled'] );
		$roles  = function_exists( 'get_editable_roles' ) ? get_editable_roles() : array();
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">MAINTENANCE · RC 40</span>
					<h1><?php esc_html_e( 'Maintenance', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Ferme la boutique pour tout le monde sauf les administrateurs, avec une page moderne, un vrai code 503 (le référencement est préservé) et aucune mise en cache possible.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['result'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo 'closed' === (string) $_GET['result'] ? esc_html__( 'Boutique fermée. Les visiteurs voient maintenant la page de maintenance.', 'delicat-builder-v9' ) : esc_html__( 'Boutique rouverte.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['rotated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Nouveau lien d’aperçu généré. L’ancien lien ne fonctionne plus.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<?php
			$close_url = wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_maintenance_toggle&state=1' ), 'delicat_builder_v9_maintenance_toggle' );
			$open_url  = wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_maintenance_toggle&state=0' ), 'delicat_builder_v9_maintenance_toggle' );
			$probe     = Delicat_Builder_V9_Maintenance::visitor_probe();
			?>
			<div class="delicat-admin__card" style="max-width:900px">
				<h2><?php esc_html_e( 'État de la boutique', 'delicat-builder-v9' ); ?></h2>
				<div class="delicat-status">
					<span class="delicat-status__dot <?php echo $on ? 'is-warn' : 'is-ok'; ?>"></span>
					<strong><?php echo $on ? esc_html__( 'Fermée (maintenance)', 'delicat-builder-v9' ) : esc_html__( 'Ouverte', 'delicat-builder-v9' ); ?></strong>
					<span><?php echo $on ? esc_html__( 'Seuls les administrateurs peuvent voir le site.', 'delicat-builder-v9' ) : esc_html__( 'Le site est accessible à tous les visiteurs.', 'delicat-builder-v9' ); ?></span>
				</div>

				<p>
					<a class="button<?php echo $on ? '' : ' button-primary'; ?>" href="<?php echo esc_url( $close_url ); ?>"><?php esc_html_e( 'Fermer la boutique', 'delicat-builder-v9' ); ?></a>
					<a class="button<?php echo $on ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $open_url ); ?>"><?php esc_html_e( 'Rouvrir la boutique', 'delicat-builder-v9' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::preview_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Voir la page de maintenance', 'delicat-builder-v9' ); ?></a>
				</p>

				<div class="delicat-warning" style="margin-top:6px">
					<strong><?php esc_html_e( 'Vous êtes administrateur : le site continuera de s’afficher normalement pour vous.', 'delicat-builder-v9' ); ?></strong>
					<p><?php esc_html_e( 'C’est voulu — c’est ce qui vous permet de travailler pendant la fermeture. Pour voir ce que voit un client, ouvrez delicastoreha.com dans une fenêtre de navigation privée. La vérification ci-dessous le fait pour vous.', 'delicat-builder-v9' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Vérification visiteur', 'delicat-builder-v9' ); ?></h3>
				<?php if ( empty( $probe['reachable'] ) ) : ?>
					<div class="delicat-status">
						<span class="delicat-status__dot is-warn"></span>
						<strong><?php esc_html_e( 'Test impossible', 'delicat-builder-v9' ); ?></strong>
						<span><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'L’hébergeur bloque la requête interne (%s). Vérifiez en navigation privée.', 'delicat-builder-v9' ), (string) $probe['error'] ) ); ?></span>
					</div>
				<?php elseif ( $on && 503 === (int) $probe['status'] ) : ?>
					<div class="delicat-status">
						<span class="delicat-status__dot is-ok"></span>
						<strong><?php esc_html_e( 'Fermeture confirmée', 'delicat-builder-v9' ); ?></strong>
						<span><?php esc_html_e( 'Un visiteur anonyme reçoit bien la page de maintenance (HTTP 503).', 'delicat-builder-v9' ); ?></span>
					</div>
				<?php elseif ( $on ) : ?>
					<div class="delicat-status">
						<span class="delicat-status__dot is-warn"></span>
						<strong><?php echo esc_html( sprintf( /* translators: %d: HTTP status code */ __( 'Un visiteur reçoit encore HTTP %d', 'delicat-builder-v9' ), (int) $probe['status'] ) ); ?></strong>
						<span><?php esc_html_e( 'Une copie en cache est encore servie : LiteSpeed → Purge All, puis rechargez cette page.', 'delicat-builder-v9' ); ?></span>
					</div>
				<?php else : ?>
					<div class="delicat-status">
						<span class="delicat-status__dot is-ok"></span>
						<strong><?php echo esc_html( sprintf( /* translators: %d: HTTP status code */ __( 'Boutique ouverte (HTTP %d)', 'delicat-builder-v9' ), (int) $probe['status'] ) ); ?></strong>
						<span><?php esc_html_e( 'Un visiteur anonyme accède normalement au site.', 'delicat-builder-v9' ); ?></span>
					</div>
				<?php endif; ?>

				<p class="description"><?php esc_html_e( 'wp-admin, wp-login.php et WP-Cron restent toujours accessibles : il est impossible de se verrouiller dehors.', 'delicat-builder-v9' ); ?></p>
			</div>

			<form method="post" action="options.php" style="margin-top:24px;max-width:900px">
				<?php settings_fields( 'delicat_builder_v9_maintenance' ); ?>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Activation', 'delicat-builder-v9' ); ?></h2>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( $on ); ?>>
						<span><strong><?php esc_html_e( 'Activer le mode maintenance', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Réponse 503 + Retry-After pour tous les visiteurs non autorisés. Aucune page n’est mise en cache pendant la fermeture.', 'delicat-builder-v9' ); ?></small></span>
					</label>
				</div>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Modèle', 'delicat-builder-v9' ); ?></h2>
					<div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))">
						<?php foreach ( self::template_cards() as $key => $meta ) : ?>
							<label style="display:block;padding:14px 16px;border:2px solid <?php echo $key === $s['template'] ? '#284696' : '#e2e4ec'; ?>;border-radius:12px;background:#fff;cursor:pointer">
								<span style="display:flex;align-items:center;gap:9px">
									<input type="radio" name="<?php echo esc_attr( $option ); ?>[template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $s['template'] ); ?>>
									<strong><?php echo esc_html( (string) $meta[0] ); ?></strong>
								</span>
								<small style="display:block;margin:8px 0 10px;color:#5b6478;line-height:1.5"><?php echo esc_html( (string) $meta[1] ); ?></small>
								<a href="<?php echo esc_url( self::preview_url( (string) $key ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Aperçu', 'delicat-builder-v9' ); ?></a>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Contenu', 'delicat-builder-v9' ); ?></h2>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Badge', 'delicat-builder-v9' ); ?></strong></label>
						<input class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[eyebrow]" value="<?php echo esc_attr( (string) $s['eyebrow'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Titre', 'delicat-builder-v9' ); ?></strong></label>
						<input class="large-text" type="text" name="<?php echo esc_attr( $option ); ?>[title]" value="<?php echo esc_attr( (string) $s['title'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Message', 'delicat-builder-v9' ); ?></strong></label>
						<textarea class="large-text" rows="3" name="<?php echo esc_attr( $option ); ?>[message]"><?php echo esc_textarea( (string) $s['message'] ); ?></textarea>
					</div>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[show_logo]" value="1" <?php checked( ! empty( $s['show_logo'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Afficher le logo', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Vide = logo du site (personnalisation du thème), sinon l’initiale de la boutique.', 'delicat-builder-v9' ); ?></small></span>
					</label>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'URL du logo', 'delicat-builder-v9' ); ?></strong></label>
						<input class="large-text" type="url" name="<?php echo esc_attr( $option ); ?>[logo_url]" value="<?php echo esc_attr( (string) $s['logo_url'] ); ?>" placeholder="https://delicastoreha.com/wp-content/uploads/logo.png">
					</div>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[show_brand]" value="1" <?php checked( ! empty( $s['show_brand'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Afficher le nom de la boutique', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Sous le logo, en gros caractères.', 'delicat-builder-v9' ); ?></small></span>
					</label>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Nom affiché', 'delicat-builder-v9' ); ?></strong></label>
						<input class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[brand_text]" value="<?php echo esc_attr( (string) $s['brand_text'] ); ?>" placeholder="Délicat|Store">
						<small><?php esc_html_e( 'Vide = nom du site. Une barre verticale coupe le nom en deux : ce qui suit prend la couleur d’accent (Délicat|Store).', 'delicat-builder-v9' ); ?></small>
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Annonces', 'delicat-builder-v9' ); ?></strong></label>
						<textarea class="large-text" rows="3" name="<?php echo esc_attr( $option ); ?>[notices]" placeholder="🎁 Les cartes-cadeaux arrivent bientôt&#10;⚡ Recharges Free Fire plus rapides"><?php echo esc_textarea( (string) $s['notices'] ); ?></textarea>
						<small><?php esc_html_e( 'Une annonce par ligne, dans un encadré sous le message. À partir de deux lignes elles défilent toutes les 5 secondes, avec des points cliquables. Maximum 6.', 'delicat-builder-v9' ); ?></small>
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Signature de bas de page', 'delicat-builder-v9' ); ?></strong></label>
						<input class="large-text" type="text" name="<?php echo esc_attr( $option ); ?>[footer_text]" value="<?php echo esc_attr( (string) $s['footer_text'] ); ?>" placeholder="Délicat Store · Recharges & Cartes-cadeaux">
					</div>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[show_admin_link]" value="1" <?php checked( ! empty( $s['show_admin_link'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Lien « Espace administrateur »', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Petit lien discret vers l’écran de connexion, en bas de la page.', 'delicat-builder-v9' ); ?></small></span>
					</label>
				</div>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Planification', 'delicat-builder-v9' ); ?></h2>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Retour prévu', 'delicat-builder-v9' ); ?></strong></label>
						<input type="datetime-local" name="<?php echo esc_attr( $option ); ?>[end_time]" value="<?php echo esc_attr( (string) $s['end_time'] ); ?>">
						<small><?php echo esc_html( sprintf( /* translators: %s: site timezone */ __( 'Heure du site (%s). Vide = aucune date affichée.', 'delicat-builder-v9' ), wp_timezone_string() ) ); ?></small>
					</div>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[countdown]" value="1" <?php checked( ! empty( $s['countdown'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Compte à rebours', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Calculé sur l’horloge du serveur : un téléphone mal réglé affiche quand même le bon délai.', 'delicat-builder-v9' ); ?></small></span>
					</label>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[progress]" value="1" <?php checked( ! empty( $s['progress'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Barre de progression', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Entre l’activation et l’heure de retour.', 'delicat-builder-v9' ); ?></small></span>
					</label>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auto_end]" value="1" <?php checked( ! empty( $s['auto_end'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Rouvrir automatiquement', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'À l’heure de retour, la boutique se rouvre seule et les caches sont purgés.', 'delicat-builder-v9' ); ?></small></span>
					</label>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Retry-After (secondes)', 'delicat-builder-v9' ); ?></strong></label>
						<input type="number" min="60" max="86400" name="<?php echo esc_attr( $option ); ?>[retry_after]" value="<?php echo esc_attr( (string) $s['retry_after'] ); ?>">
						<small><?php esc_html_e( 'Utilisé quand aucune heure de retour n’est définie. Indique aux moteurs de recherche quand revenir.', 'delicat-builder-v9' ); ?></small>
					</div>
				</div>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Couleurs', 'delicat-builder-v9' ); ?></h2>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Accent', 'delicat-builder-v9' ); ?></strong></label>
						<input type="color" name="<?php echo esc_attr( $option ); ?>[accent]" value="<?php echo esc_attr( (string) $s['accent'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Fond — début', 'delicat-builder-v9' ); ?></strong></label>
						<input type="color" name="<?php echo esc_attr( $option ); ?>[bg_start]" value="<?php echo esc_attr( (string) $s['bg_start'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Fond — fin', 'delicat-builder-v9' ); ?></strong></label>
						<input type="color" name="<?php echo esc_attr( $option ); ?>[bg_end]" value="<?php echo esc_attr( (string) $s['bg_end'] ); ?>">
					</div>
				</div>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Contact et réseaux', 'delicat-builder-v9' ); ?></h2>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'WhatsApp (chiffres uniquement, avec indicatif)', 'delicat-builder-v9' ); ?></strong></label>
						<input class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[whatsapp]" value="<?php echo esc_attr( (string) $s['whatsapp'] ); ?>" placeholder="509XXXXXXXX">
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'E-mail', 'delicat-builder-v9' ); ?></strong></label>
						<input class="regular-text" type="email" name="<?php echo esc_attr( $option ); ?>[email]" value="<?php echo esc_attr( (string) $s['email'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Téléphone', 'delicat-builder-v9' ); ?></strong></label>
						<input class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[phone]" value="<?php echo esc_attr( (string) $s['phone'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong>Facebook</strong></label>
						<input class="large-text" type="url" name="<?php echo esc_attr( $option ); ?>[facebook]" value="<?php echo esc_attr( (string) $s['facebook'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong>Instagram</strong></label>
						<input class="large-text" type="url" name="<?php echo esc_attr( $option ); ?>[instagram]" value="<?php echo esc_attr( (string) $s['instagram'] ); ?>">
					</div>
					<div class="delicat-field">
						<label><strong>TikTok</strong></label>
						<input class="large-text" type="url" name="<?php echo esc_attr( $option ); ?>[tiktok]" value="<?php echo esc_attr( (string) $s['tiktok'] ); ?>">
					</div>
				</div>

				<div class="delicat-admin__card">
					<h2><?php esc_html_e( 'Accès pendant la fermeture', 'delicat-builder-v9' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Les administrateurs voient toujours le site. Tout le reste ci-dessous est facultatif.', 'delicat-builder-v9' ); ?></p>

					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Rôles autorisés en plus', 'delicat-builder-v9' ); ?></strong></label>
						<?php foreach ( $roles as $role_key => $role_data ) : ?>
							<?php if ( 'administrator' === $role_key ) { continue; } ?>
							<label style="display:block;margin:5px 0">
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[allowed_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $s['allowed_roles'], true ) ); ?>>
								<?php echo esc_html( translate_user_role( (string) ( $role_data['name'] ?? $role_key ) ) ); ?>
							</label>
						<?php endforeach; ?>
					</div>

					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Adresses IP autorisées', 'delicat-builder-v9' ); ?></strong></label>
						<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option ); ?>[allow_ips]" placeholder="190.115.24.10, 190.115.24.*"><?php echo esc_textarea( (string) $s['allow_ips'] ); ?></textarea>
						<small><?php esc_html_e( 'Séparées par des virgules ou des retours à la ligne. Le joker * final est accepté.', 'delicat-builder-v9' ); ?></small>
					</div>

					<div class="delicat-field">
						<label><strong><?php esc_html_e( 'Lien d’aperçu secret', 'delicat-builder-v9' ); ?></strong></label>
						<input class="large-text" type="text" readonly onclick="this.select()" value="<?php echo esc_attr( Delicat_Builder_V9_Maintenance::bypass_url() ); ?>">
						<small><?php esc_html_e( 'À partager avec un client ou un collègue : ce lien ouvre le site normal pendant 12 heures, sans compte.', 'delicat-builder-v9' ); ?></small>
						<p><a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_maintenance_rotate' ), 'delicat_builder_v9_maintenance_rotate' ) ); ?>"><?php esc_html_e( 'Générer un nouveau lien', 'delicat-builder-v9' ); ?></a></p>
					</div>

					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[lock_api]" value="1" <?php checked( ! empty( $s['lock_api'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Fermer aussi les API', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'REST, admin-ajax, wc-ajax et XML-RPC répondent 503. Sans cela, un script peut encore lire les produits et le panier pendant la fermeture. Les actions de connexion restent ouvertes.', 'delicat-builder-v9' ); ?></small></span>
					</label>
					<label class="delicat-switch">
						<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[allow_payment_callbacks]" value="1" <?php checked( ! empty( $s['allow_payment_callbacks'] ) ); ?>>
						<span><strong><?php esc_html_e( 'Laisser passer les callbacks de paiement', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Les notifications WooCommerce (wc-api) restent joignables, pour qu’un paiement MonCash/NatCash lancé avant la fermeture puisse encore être confirmé. Recommandé.', 'delicat-builder-v9' ); ?></small></span>
					</label>
				</div>

				<?php submit_button( __( 'Enregistrer', 'delicat-builder-v9' ) ); ?>
			</form>
		</div>
		<?php
	}
}
