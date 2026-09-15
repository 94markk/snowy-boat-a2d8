<?php
/**
 * Menu Builder — the admin screen for the Menu Engine.
 *
 * This file used to carry a second, complete copy of the front-end runtime:
 * the same thousand lines as the runtime module, declared behind a guard so
 * that parsing both in one request was not a fatal. Rendering now lives in
 * class-delicat-builder-menu-engine.php and nowhere else; what is left here is
 * the screen, its save handler, and the adapter class other modules call.
 *
 * Controls that no longer drive anything are gone rather than left on the
 * screen doing nothing: the glass presets, the light/dark "models", the blur
 * slider, the stagger/hover pickers. The engine they configured has been
 * deleted, and a switch that silently does nothing is worse than no switch.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Delicat_Builder_V9_Menu_Engine', false ) ) {
	require_once __DIR__ . '/class-delicat-builder-menu-engine.php';
}

if ( ! class_exists( 'Delicat_Builder_V9_Menu_Builder', false ) ) :

/**
 * Adapter kept under its historic name: Core boots it, Header Studio renders
 * through it, and third-party snippets call ::render().
 */
final class Delicat_Builder_V9_Menu_Builder {

	public const SETTINGS_OPTION = Delicat_Builder_V9_Menu_Engine::SETTINGS_OPTION;
	public const ITEMS_OPTION    = Delicat_Builder_V9_Menu_Engine::ITEMS_OPTION;
	public const QUICK_OPTION    = Delicat_Builder_V9_Menu_Engine::QUICK_OPTION;

	public static function boot(): void {
		Delicat_Builder_V9_Menu_Engine::boot();

		if ( is_admin() ) {
			add_action( 'admin_init', array( __CLASS__, 'save' ) );
		}
	}

	public static function settings(): array {
		return Delicat_Builder_V9_Menu_Engine::settings();
	}

	public static function items(): array {
		return Delicat_Builder_V9_Menu_Engine::items();
	}

	public static function render( string $mode = 'drawer' ): string {
		if ( function_exists( 'dsb_render_modern_menu' ) ) {
			return (string) dsb_render_modern_menu( $mode );
		}
		return 'inline' === $mode
			? Delicat_Builder_V9_Menu_Engine::render_inline()
			: Delicat_Builder_V9_Menu_Engine::render();
	}

	/* ------------------------------------------------------------------ */
	/* save                                                                */
	/* ------------------------------------------------------------------ */

	public static function save(): void {
		if ( ! isset( $_POST['delicat_builder_v9_menu_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if (
			! isset( $_POST['delicat_builder_v9_menu_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['delicat_builder_v9_menu_nonce'] ) ), 'delicat_builder_v9_menu_builder' )
		) {
			return;
		}

		$settings = isset( $_POST['dsb_menu_builder'] ) && is_array( $_POST['dsb_menu_builder'] ) ? wp_unslash( $_POST['dsb_menu_builder'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalized field by field below.
		$items    = isset( $_POST['dsb_menu_items'] ) && is_array( $_POST['dsb_menu_items'] ) ? wp_unslash( $_POST['dsb_menu_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalized field by field below.
		/* An absent key means every row was deleted, which is a valid choice;
		 * storing the empty array is what stops the legacy four-slot row of
		 * quick actions coming back. */
		$quick = isset( $_POST['dsb_menu_quick'] ) && is_array( $_POST['dsb_menu_quick'] ) ? wp_unslash( $_POST['dsb_menu_quick'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- normalized field by field below.

		update_option( self::SETTINGS_OPTION, Delicat_Builder_V9_Menu_Engine::normalize_settings( $settings ), false );
		update_option( self::ITEMS_OPTION, Delicat_Builder_V9_Menu_Engine::normalize_items( $items ), false );
		update_option( self::QUICK_OPTION, Delicat_Builder_V9_Menu_Engine::normalize_quick( $quick ), false );

		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=delicat-builder-v9-menu-builder&msg=saved' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* screen                                                              */
	/* ------------------------------------------------------------------ */

	private static function field( array $s, string $key, string $label, string $type = 'text' ): void {
		printf(
			'<div class="dsb-menu-field"><label>%1$s</label><input data-setting="%2$s" type="%3$s" name="dsb_menu_builder[%2$s]" value="%4$s"></div>',
			esc_html( $label ),
			esc_attr( $key ),
			esc_attr( $type ),
			esc_attr( (string) ( $s[ $key ] ?? '' ) )
		);
	}

	private static function choice( array $s, string $key, string $label, array $options ): void {
		echo '<div class="dsb-menu-field"><label>' . esc_html( $label ) . '</label><select data-setting="' . esc_attr( $key ) . '" name="dsb_menu_builder[' . esc_attr( $key ) . ']">';
		foreach ( $options as $value => $text ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $s[ $key ] ?? '', $value, false ) . '>' . esc_html( (string) $text ) . '</option>';
		}
		echo '</select></div>';
	}

	private static function yes_no( array $s, string $key, string $label ): void {
		self::choice( $s, $key, $label, array( 'yes' => __( 'Oui', 'delicat-builder-v9' ), 'no' => __( 'Non', 'delicat-builder-v9' ) ) );
	}

	/** The Editor screen calls this name. */
	public static function admin_page(): void {
		self::page();
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot edit the Menu Builder.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}

		wp_enqueue_media();
		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/menu-builder-v2.css';
		$js  = DELICAT_BUILDER_V9_DIR . 'assets/js/menu-builder-v2.js';
		wp_enqueue_style( 'delicat-builder-v9-menu-builder-v2', DELICAT_BUILDER_V9_URL . 'assets/css/menu-builder-v2.css', array(), is_file( $css ) ? (string) filemtime( $css ) : DELICAT_BUILDER_V9_VERSION );
		wp_enqueue_script( 'delicat-builder-v9-menu-builder-v2', DELICAT_BUILDER_V9_URL . 'assets/js/menu-builder-v2.js', array(), is_file( $js ) ? (string) filemtime( $js ) : DELICAT_BUILDER_V9_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'delicat-builder-v9-menu-builder-v2', 'strategy', 'defer' );
		}

		$s     = Delicat_Builder_V9_Menu_Engine::settings();
		$items = Delicat_Builder_V9_Menu_Engine::items();
		$quick = Delicat_Builder_V9_Menu_Engine::quick_items();
		$saved = isset( $_GET['msg'] ) && 'saved' === $_GET['msg']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after our own redirect.

		$sections = array(
			'profile' => __( 'Profil', 'delicat-builder-v9' ),
			'wallet'  => __( 'Portefeuille', 'delicat-builder-v9' ),
			'quick'   => __( 'Actions rapides', 'delicat-builder-v9' ),
			'main'    => __( 'Navigation principale', 'delicat-builder-v9' ),
			'bonus'   => __( 'Bonus', 'delicat-builder-v9' ),
			'promo'   => __( 'Promotion', 'delicat-builder-v9' ),
			'social'  => __( 'Réseaux sociaux', 'delicat-builder-v9' ),
			'footer'  => __( 'Thème / Footer', 'delicat-builder-v9' ),
		);
		$socials = array(
			'whatsapp'  => 'WhatsApp',
			'telegram'  => 'Telegram',
			'instagram' => 'Instagram',
			'facebook'  => 'Facebook',
			'tiktok'    => 'TikTok',
			'youtube'   => 'YouTube',
		);
		?>
		<div class="wrap dbv9-mb" id="dbv9MenuBuilder">
		<form method="post" id="dbv9MenuBuilderForm" autocomplete="off">
			<?php wp_nonce_field( 'delicat_builder_v9_menu_builder', 'delicat_builder_v9_menu_nonce' ); ?>
			<input type="hidden" name="delicat_builder_v9_menu_action" value="save">

			<header class="dbv9-mb-topbar">
				<div class="dbv9-mb-brand">
					<span class="dbv9-mb-kicker">DELICAT BUILDER V9 · MENU ENGINE 3.0</span>
					<h1><?php esc_html_e( 'Menu Builder', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Un seul moteur : panneau inerte au chargement, hydraté hors du chemin critique, animations transform/opacity uniquement.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="dbv9-mb-actions">
					<span class="dbv9-mb-state" id="dbv9MbState"><i></i><span><?php echo $saved ? esc_html__( 'Enregistré', 'delicat-builder-v9' ) : esc_html__( 'À jour', 'delicat-builder-v9' ); ?></span></span>
					<button type="submit" class="button button-primary dbv9-mb-save"><?php esc_html_e( 'Enregistrer', 'delicat-builder-v9' ); ?></button>
				</div>
			</header>

			<div class="dbv9-mb-layout">
				<aside class="dbv9-mb-nav" aria-label="<?php esc_attr_e( 'Sections du Menu Builder', 'delicat-builder-v9' ); ?>">
					<button type="button" class="is-active" data-mb-tab="general"><span>01</span> <?php esc_html_e( 'Général', 'delicat-builder-v9' ); ?></button>
					<button type="button" data-mb-tab="appearance"><span>02</span> <?php esc_html_e( 'Apparence', 'delicat-builder-v9' ); ?></button>
					<button type="button" data-mb-tab="identity"><span>03</span> <?php esc_html_e( 'Profil & Wallet', 'delicat-builder-v9' ); ?></button>
					<button type="button" data-mb-tab="widgets"><span>04</span> <?php esc_html_e( 'Widgets', 'delicat-builder-v9' ); ?></button>
					<button type="button" data-mb-tab="navigation"><span>05</span> <?php esc_html_e( 'Navigation', 'delicat-builder-v9' ); ?></button>
					<button type="button" data-mb-tab="social"><span>06</span> <?php esc_html_e( 'Réseaux sociaux', 'delicat-builder-v9' ); ?></button>
					<button type="button" data-mb-tab="performance"><span>07</span> <?php esc_html_e( 'Performance', 'delicat-builder-v9' ); ?></button>
				</aside>

				<main class="dbv9-mb-content">

					<section class="dbv9-mb-panel is-active" data-mb-panel="general">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Moteur', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Comportement général', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Ouverture, côté et ordre des sections.', 'delicat-builder-v9' ); ?></p></div></div>
						<div class="dbv9-mb-card-grid">
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Activation & disposition', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'enabled', __( 'Activer le menu', 'delicat-builder-v9' ) );
								self::choice( $s, 'layout', __( 'Disposition', 'delicat-builder-v9' ), array( 'drawer' => __( 'Tiroir', 'delicat-builder-v9' ), 'inline' => __( 'Inline', 'delicat-builder-v9' ) ) );
								self::choice( $s, 'position', __( 'Ouverture', 'delicat-builder-v9' ), array( 'left' => __( 'Gauche', 'delicat-builder-v9' ), 'right' => __( 'Droite', 'delicat-builder-v9' ) ) );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Ordre des sections', 'delicat-builder-v9' ); ?></h3>
								<p class="dbv9-mb-muted"><?php esc_html_e( 'Glissez sur ordinateur ou utilisez les flèches sur mobile.', 'delicat-builder-v9' ); ?></p>
								<input type="hidden" id="dsbSectionOrder" name="dsb_menu_builder[section_order]" value="<?php echo esc_attr( $s['section_order'] ); ?>">
								<div class="dbv9-sort-list" id="dsbSectionSorter">
									<?php foreach ( explode( ',', $s['section_order'] ) as $section ) : ?>
										<?php if ( ! isset( $sections[ $section ] ) ) { continue; } ?>
										<div class="dbv9-sort-item" draggable="true" data-section="<?php echo esc_attr( $section ); ?>"><button type="button" class="dbv9-drag" aria-label="<?php esc_attr_e( 'Déplacer', 'delicat-builder-v9' ); ?>">⠿</button><strong><?php echo esc_html( $sections[ $section ] ); ?></strong><span class="dbv9-row-move"><button type="button" data-move="up" aria-label="<?php esc_attr_e( 'Monter', 'delicat-builder-v9' ); ?>">↑</button><button type="button" data-move="down" aria-label="<?php esc_attr_e( 'Descendre', 'delicat-builder-v9' ); ?>">↓</button></span></div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</section>

					<section class="dbv9-mb-panel" data-mb-panel="appearance">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Design system', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Apparence', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Le clair et le sombre suivent le thème de la boutique. Aucun flou : sur un téléphone d’entrée de gamme un panneau flouté coûte une image entière par frame.', 'delicat-builder-v9' ); ?></p></div></div>
						<div class="dbv9-mb-card-grid">
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Couleurs', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields dbv9-mb-colors">
								<?php
								self::field( $s, 'primary', __( 'Primaire', 'delicat-builder-v9' ), 'color' );
								self::field( $s, 'secondary', __( 'Secondaire', 'delicat-builder-v9' ), 'color' );
								self::field( $s, 'accent', __( 'Accent', 'delicat-builder-v9' ), 'color' );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Forme', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::field( $s, 'radius', __( 'Rayon du panneau (px)', 'delicat-builder-v9' ), 'number' );
								self::field( $s, 'item_radius', __( 'Rayon des items (px)', 'delicat-builder-v9' ), 'number' );
								self::field( $s, 'overlay_opacity', __( 'Opacité overlay (%)', 'delicat-builder-v9' ), 'number' );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'En-tête du panneau', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'custom_header_enabled', __( 'Afficher l’en-tête', 'delicat-builder-v9' ) );
								self::field( $s, 'custom_header_title', __( 'Titre', 'delicat-builder-v9' ) );
								self::field( $s, 'custom_header_subtitle', __( 'Sous-titre', 'delicat-builder-v9' ) );
								self::field( $s, 'custom_header_image', __( 'Logo / image', 'delicat-builder-v9' ), 'url' );
								self::field( $s, 'custom_header_url', __( 'Lien', 'delicat-builder-v9' ), 'url' );
								?>
							</div></div>
						</div>
					</section>

					<section class="dbv9-mb-panel" data-mb-panel="identity">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Compte client', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Profil & portefeuille', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Le solde est lu à l’ouverture du menu, jamais sur minuterie.', 'delicat-builder-v9' ); ?></p></div></div>
						<div class="dbv9-mb-card-grid">
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Profil', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'show_profile', __( 'Afficher le profil', 'delicat-builder-v9' ) );
								self::field( $s, 'profile_badge', __( 'Badge', 'delicat-builder-v9' ) );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Portefeuille', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'show_wallet', __( 'Afficher le wallet', 'delicat-builder-v9' ) );
								self::field( $s, 'wallet_label', __( 'Libellé', 'delicat-builder-v9' ) );
								self::yes_no( $s, 'wallet_item_balance', __( 'Solde sur les liens wallet', 'delicat-builder-v9' ) );
								self::field( $s, 'wallet_url', __( 'Lien wallet', 'delicat-builder-v9' ), 'url' );
								self::field( $s, 'recharge_url', __( 'Lien recharge', 'delicat-builder-v9' ), 'url' );
								self::field( $s, 'loyalty_points', __( 'Points', 'delicat-builder-v9' ) );
								?>
							</div></div>
						</div>
					</section>

					<section class="dbv9-mb-panel" data-mb-panel="widgets">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Modules', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Widgets', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Chaque widget est rendu dans le panneau inerte : il ne coûte rien tant que le menu n’est pas ouvert.', 'delicat-builder-v9' ); ?></p></div></div>
						<div class="dbv9-mb-card-grid">
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Promotion', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'promo_enabled', __( 'Afficher', 'delicat-builder-v9' ) );
								self::field( $s, 'promo_title', __( 'Titre', 'delicat-builder-v9' ) );
								self::field( $s, 'promo_text', __( 'Texte', 'delicat-builder-v9' ) );
								self::field( $s, 'promo_button', __( 'Bouton', 'delicat-builder-v9' ) );
								self::field( $s, 'promo_url', __( 'Lien', 'delicat-builder-v9' ), 'url' );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Bonus & footer', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'bonus_enabled', __( 'Section bonus', 'delicat-builder-v9' ) );
								self::field( $s, 'bonus_title', __( 'Titre bonus', 'delicat-builder-v9' ) );
								self::yes_no( $s, 'footer_toggle', __( 'Toggle thème', 'delicat-builder-v9' ) );
								?>
							</div></div>
						</div>
						<div class="dbv9-mb-card">
							<div class="dbv9-mb-card-title-row"><div><h3><?php esc_html_e( 'Actions rapides', 'delicat-builder-v9' ); ?></h3><p class="dbv9-mb-muted"><?php esc_html_e( 'Ajoutez, réorganisez ou retirez les raccourcis.', 'delicat-builder-v9' ); ?></p></div><?php self::yes_no( $s, 'quick_actions', __( 'Activer', 'delicat-builder-v9' ) ); ?></div>
							<div class="dbv9-repeat-list" id="dsbQuickItems">
								<?php foreach ( $quick as $i => $tile ) : ?>
									<div class="dbv9-repeat-row" draggable="true" data-repeat="quick">
										<div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button></div>
										<div class="dbv9-repeat-grid quick-grid">
											<label><?php esc_html_e( 'Icône', 'delicat-builder-v9' ); ?><input name="dsb_menu_quick[<?php echo (int) $i; ?>][icon]" value="<?php echo esc_attr( $tile['icon'] ); ?>"></label>
											<label><?php esc_html_e( 'Image/SVG', 'delicat-builder-v9' ); ?><input class="dsb-menu-image-url" name="dsb_menu_quick[<?php echo (int) $i; ?>][image]" value="<?php echo esc_url( $tile['image'] ); ?>"><input type="hidden" class="dsb-menu-attachment-id" name="dsb_menu_quick[<?php echo (int) $i; ?>][attachment_id]" value="<?php echo absint( $tile['attachment_id'] ); ?>"><button type="button" class="button dsb-menu-upload"><?php esc_html_e( 'Média', 'delicat-builder-v9' ); ?></button></label>
											<label><?php esc_html_e( 'Libellé', 'delicat-builder-v9' ); ?><input name="dsb_menu_quick[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $tile['label'] ); ?>"></label>
											<label><?php esc_html_e( 'Lien', 'delicat-builder-v9' ); ?><input name="dsb_menu_quick[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $tile['url'] ); ?>"></label>
											<label><?php esc_html_e( 'Visibilité', 'delicat-builder-v9' ); ?><select name="dsb_menu_quick[<?php echo (int) $i; ?>][visibility]"><option value="all" <?php selected( $tile['visibility'], 'all' ); ?>><?php esc_html_e( 'Tous', 'delicat-builder-v9' ); ?></option><option value="logged_in" <?php selected( $tile['visibility'], 'logged_in' ); ?>><?php esc_html_e( 'Connectés', 'delicat-builder-v9' ); ?></option><option value="logged_out" <?php selected( $tile['visibility'], 'logged_out' ); ?>><?php esc_html_e( 'Invités', 'delicat-builder-v9' ); ?></option></select></label>
										</div>
										<div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove" aria-label="<?php esc_attr_e( 'Supprimer', 'delicat-builder-v9' ); ?>">×</button></div>
									</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="button dbv9-add-row" data-add="quick"><?php esc_html_e( '+ Ajouter un raccourci', 'delicat-builder-v9' ); ?></button>
						</div>
					</section>

					<section class="dbv9-mb-panel" data-mb-panel="navigation">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Structure', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Navigation', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Au-delà de sept entrées, un champ de recherche apparaît automatiquement dans le menu.', 'delicat-builder-v9' ); ?></p></div><div class="dbv9-mb-search"><input type="search" id="dbv9MenuItemSearch" placeholder="<?php esc_attr_e( 'Rechercher un item…', 'delicat-builder-v9' ); ?>"></div></div>
						<div class="dbv9-repeat-list" id="dsbMenuItems">
							<?php foreach ( $items as $i => $item ) : ?>
								<div class="dbv9-repeat-row" draggable="true" data-repeat="menu" data-search="<?php echo esc_attr( strtolower( $item['title'] . ' ' . $item['subtitle'] ) ); ?>">
									<div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button><span class="dbv9-item-icon-dot" style="--c1:<?php echo esc_attr( $item['color1'] ); ?>;--c2:<?php echo esc_attr( $item['color2'] ); ?>"></span></div>
									<div class="dbv9-repeat-grid menu-grid">
										<label><?php esc_html_e( 'Titre', 'delicat-builder-v9' ); ?><input name="dsb_menu_items[<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr( $item['title'] ); ?>"></label>
										<label><?php esc_html_e( 'Sous-titre', 'delicat-builder-v9' ); ?><input name="dsb_menu_items[<?php echo (int) $i; ?>][subtitle]" value="<?php echo esc_attr( $item['subtitle'] ); ?>"></label>
										<label><?php esc_html_e( 'Lien', 'delicat-builder-v9' ); ?><input name="dsb_menu_items[<?php echo (int) $i; ?>][url]" value="<?php echo esc_attr( $item['url'] ); ?>"></label>
										<label><?php esc_html_e( 'Icône native', 'delicat-builder-v9' ); ?><input name="dsb_menu_items[<?php echo (int) $i; ?>][icon]" value="<?php echo esc_attr( $item['icon'] ); ?>"></label>
										<label><?php esc_html_e( 'Image/SVG', 'delicat-builder-v9' ); ?><input class="dsb-menu-image-url" name="dsb_menu_items[<?php echo (int) $i; ?>][image]" value="<?php echo esc_url( $item['image'] ); ?>"><input type="hidden" class="dsb-menu-attachment-id" name="dsb_menu_items[<?php echo (int) $i; ?>][attachment_id]" value="<?php echo absint( $item['attachment_id'] ); ?>"><button type="button" class="button dsb-menu-upload"><?php esc_html_e( 'Média', 'delicat-builder-v9' ); ?></button></label>
										<label><?php esc_html_e( 'Couleur 1', 'delicat-builder-v9' ); ?><input type="color" name="dsb_menu_items[<?php echo (int) $i; ?>][color1]" value="<?php echo esc_attr( $item['color1'] ); ?>"></label>
										<label><?php esc_html_e( 'Couleur 2', 'delicat-builder-v9' ); ?><input type="color" name="dsb_menu_items[<?php echo (int) $i; ?>][color2]" value="<?php echo esc_attr( $item['color2'] ); ?>"></label>
										<label><?php esc_html_e( 'Badge', 'delicat-builder-v9' ); ?><input name="dsb_menu_items[<?php echo (int) $i; ?>][badge]" value="<?php echo esc_attr( $item['badge'] ); ?>"></label>
										<label><?php esc_html_e( 'Groupe', 'delicat-builder-v9' ); ?><select name="dsb_menu_items[<?php echo (int) $i; ?>][group]"><option value="main" <?php selected( $item['group'], 'main' ); ?>><?php esc_html_e( 'Principal', 'delicat-builder-v9' ); ?></option><option value="bonus" <?php selected( $item['group'], 'bonus' ); ?>><?php esc_html_e( 'Bonus', 'delicat-builder-v9' ); ?></option><option value="footer" <?php selected( $item['group'], 'footer' ); ?>><?php esc_html_e( 'Footer', 'delicat-builder-v9' ); ?></option><option value="quick" <?php selected( $item['group'], 'quick' ); ?>><?php esc_html_e( 'Quick', 'delicat-builder-v9' ); ?></option></select></label>
										<label><?php esc_html_e( 'Visibilité', 'delicat-builder-v9' ); ?><select name="dsb_menu_items[<?php echo (int) $i; ?>][visibility]"><option value="all" <?php selected( $item['visibility'], 'all' ); ?>><?php esc_html_e( 'Tous', 'delicat-builder-v9' ); ?></option><option value="logged_in" <?php selected( $item['visibility'], 'logged_in' ); ?>><?php esc_html_e( 'Connectés', 'delicat-builder-v9' ); ?></option><option value="logged_out" <?php selected( $item['visibility'], 'logged_out' ); ?>><?php esc_html_e( 'Invités', 'delicat-builder-v9' ); ?></option></select></label>
									</div>
									<div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove" aria-label="<?php esc_attr_e( 'Supprimer', 'delicat-builder-v9' ); ?>">×</button></div>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button dbv9-add-row" data-add="menu"><?php esc_html_e( '+ Ajouter un item', 'delicat-builder-v9' ); ?></button>
					</section>

					<section class="dbv9-mb-panel" data-mb-panel="social">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Liens externes', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Réseaux sociaux', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Une URL qui ne pointe pas vers le réseau choisi est ignorée : c’est un espace réservé, pas un lien.', 'delicat-builder-v9' ); ?></p></div></div>
						<div class="dbv9-mb-card"><div class="dbv9-mb-fields">
							<?php self::yes_no( $s, 'social_enabled', __( 'Afficher les réseaux', 'delicat-builder-v9' ) ); ?>
						</div></div>
						<div class="dbv9-social-list">
							<?php foreach ( $socials as $slug => $label ) : ?>
								<div class="dbv9-social-row">
									<label class="dbv9-switch"><input type="hidden" name="dsb_menu_builder[<?php echo esc_attr( $slug ); ?>_enabled]" value="no"><input type="checkbox" name="dsb_menu_builder[<?php echo esc_attr( $slug ); ?>_enabled]" value="yes" <?php checked( $s[ $slug . '_enabled' ], 'yes' ); ?>><span></span></label>
									<strong><?php echo esc_html( $label ); ?></strong>
									<label><?php esc_html_e( 'Libellé', 'delicat-builder-v9' ); ?><input name="dsb_menu_builder[<?php echo esc_attr( $slug ); ?>_label]" value="<?php echo esc_attr( $s[ $slug . '_label' ] ); ?>"></label>
									<label><?php esc_html_e( 'URL', 'delicat-builder-v9' ); ?><input type="url" name="dsb_menu_builder[<?php echo esc_attr( $slug ); ?>_url]" value="<?php echo esc_url( $s[ $slug . '_url' ] ); ?>"></label>
								</div>
							<?php endforeach; ?>
						</div>
					</section>

					<section class="dbv9-mb-panel" data-mb-panel="performance">
						<div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow"><?php esc_html_e( 'Low-end first', 'delicat-builder-v9' ); ?></span><h2><?php esc_html_e( 'Responsive & performance', 'delicat-builder-v9' ); ?></h2><p><?php esc_html_e( 'Le mode Auto détecte Data Saver, mémoire limitée, faible nombre de cœurs et Reduced Motion, puis raccourcit l’animation et retire les ombres.', 'delicat-builder-v9' ); ?></p></div></div>
						<div class="dbv9-mb-card-grid">
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Mode de performance', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::choice(
									$s,
									'performance_mode',
									__( 'Profil', 'delicat-builder-v9' ),
									array(
										'auto'       => __( 'Auto adaptatif (recommandé)', 'delicat-builder-v9' ),
										'balanced'   => __( 'Qualité équilibrée', 'delicat-builder-v9' ),
										'ultra-lite' => __( 'Ultra léger', 'delicat-builder-v9' ),
									)
								);
								self::choice( $s, 'reduced_motion', __( 'Reduced Motion', 'delicat-builder-v9' ), array( 'respect' => __( 'Respecter', 'delicat-builder-v9' ), 'ignore' => __( 'Ignorer', 'delicat-builder-v9' ) ) );
								self::field( $s, 'open_speed', __( 'Vitesse ouverture (ms)', 'delicat-builder-v9' ), 'number' );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Largeurs', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::field( $s, 'desktop_width', __( 'Desktop (px)', 'delicat-builder-v9' ), 'number' );
								self::field( $s, 'tablet_width', __( 'Tablette (px)', 'delicat-builder-v9' ), 'number' );
								self::field( $s, 'mobile_width', __( 'Mobile', 'delicat-builder-v9' ), 'number' );
								self::choice( $s, 'mobile_unit', __( 'Unité mobile', 'delicat-builder-v9' ), array( 'vw' => 'vw', '%' => '%' ) );
								?>
							</div></div>
							<div class="dbv9-mb-card"><h3><?php esc_html_e( 'Gestes & navigation', 'delicat-builder-v9' ); ?></h3><div class="dbv9-mb-fields">
								<?php
								self::yes_no( $s, 'close_on_overlay', __( 'Fermer via overlay', 'delicat-builder-v9' ) );
								self::yes_no( $s, 'body_scroll_lock', __( 'Bloquer le scroll', 'delicat-builder-v9' ) );
								self::yes_no( $s, 'swipe_close', __( 'Swipe pour fermer', 'delicat-builder-v9' ) );
								self::yes_no( $s, 'bottom_nav', __( 'Bottom navigation', 'delicat-builder-v9' ) );
								?>
							</div></div>
						</div>
						<div class="dbv9-perf-note"><strong><?php esc_html_e( 'Architecture Menu Engine 3.0', 'delicat-builder-v9' ); ?></strong><span><?php esc_html_e( 'Panneau livré dans un <template> inerte · hydratation en temps mort · rendu en fin de document · animations transform/opacity · aucun flou · solde lu à l’ouverture, sans minuterie.', 'delicat-builder-v9' ); ?></span></div>
					</section>

				</main>

				<aside class="dbv9-mb-preview-wrap">
					<div class="dbv9-mb-preview-card">
						<div class="dbv9-preview-head"><div><span><?php esc_html_e( 'Aperçu', 'delicat-builder-v9' ); ?></span><strong><?php esc_html_e( 'Mobile', 'delicat-builder-v9' ); ?></strong></div><span class="dbv9-preview-dot"></span></div>
						<div class="dbv9-phone" id="dbv9MenuPreview" style="--p1:<?php echo esc_attr( $s['primary'] ); ?>;--p2:<?php echo esc_attr( $s['secondary'] ); ?>;--pa:<?php echo esc_attr( $s['accent'] ); ?>;--pr:<?php echo (int) $s['radius']; ?>px;--pir:<?php echo (int) $s['item_radius']; ?>px;">
							<div class="dbv9-phone-top"><span class="dbv9-preview-logo">D</span><div><strong id="dbv9PreviewTitle"><?php echo esc_html( $s['custom_header_title'] ); ?></strong><small id="dbv9PreviewSubtitle"><?php echo esc_html( $s['custom_header_subtitle'] ); ?></small></div><button type="button" tabindex="-1">×</button></div>
							<div class="dbv9-preview-profile"><span>👤</span><div><small><?php esc_html_e( 'Bienvenue', 'delicat-builder-v9' ); ?></small><strong><?php esc_html_e( 'Client Delicat', 'delicat-builder-v9' ); ?></strong></div></div>
							<div class="dbv9-preview-wallet"><div><small><?php echo esc_html( $s['wallet_label'] ); ?></small><strong>G 1,250</strong></div><button type="button" tabindex="-1"><?php esc_html_e( 'Recharger', 'delicat-builder-v9' ); ?></button></div>
							<div class="dbv9-preview-quick"><span>💳<small>Wallet</small></span><span>📦<small>Commandes</small></span><span>💬<small>Support</small></span></div>
							<div class="dbv9-preview-links" id="dbv9PreviewLinks">
								<?php foreach ( array_slice( $items, 0, 4 ) as $item ) : ?>
									<span><i style="--c1:<?php echo esc_attr( $item['color1'] ); ?>;--c2:<?php echo esc_attr( $item['color2'] ); ?>"></i><b><?php echo esc_html( $item['title'] ); ?></b><em>›</em></span>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="dbv9-preview-foot"><span><i></i> <?php esc_html_e( 'Aperçu local instantané', 'delicat-builder-v9' ); ?></span><small><?php esc_html_e( 'Sans requête réseau', 'delicat-builder-v9' ); ?></small></div>
					</div>
				</aside>
			</div>

			<template id="dbv9MenuRowTemplate"><div class="dbv9-repeat-row" draggable="true" data-repeat="menu"><div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button><span class="dbv9-item-icon-dot"></span></div><div class="dbv9-repeat-grid menu-grid"><label>Titre<input data-name="title" placeholder="Titre"></label><label>Sous-titre<input data-name="subtitle" placeholder="Sous-titre"></label><label>Lien<input data-name="url" placeholder="/lien/"></label><label>Icône native<input data-name="icon" value="dashicons-arrow-right-alt2"></label><label>Image/SVG<input class="dsb-menu-image-url" data-name="image"><input type="hidden" class="dsb-menu-attachment-id" data-name="attachment_id" value="0"><button type="button" class="button dsb-menu-upload">Média</button></label><label>Couleur 1<input type="color" data-name="color1" value="#8b5cf6"></label><label>Couleur 2<input type="color" data-name="color2" value="#ec4899"></label><label>Badge<input data-name="badge"></label><label>Groupe<select data-name="group"><option value="main">Principal</option><option value="bonus">Bonus</option><option value="footer">Footer</option><option value="quick">Quick</option></select></label><label>Visibilité<select data-name="visibility"><option value="all">Tous</option><option value="logged_in">Connectés</option><option value="logged_out">Invités</option></select></label></div><div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove">×</button></div></div></template>
			<template id="dbv9QuickRowTemplate"><div class="dbv9-repeat-row" draggable="true" data-repeat="quick"><div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button></div><div class="dbv9-repeat-grid quick-grid"><label>Icône<input data-name="icon" placeholder="⭐"></label><label>Image/SVG<input class="dsb-menu-image-url" data-name="image"><input type="hidden" class="dsb-menu-attachment-id" data-name="attachment_id" value="0"><button type="button" class="button dsb-menu-upload">Média</button></label><label>Libellé<input data-name="label" placeholder="Raccourci"></label><label>Lien<input data-name="url" placeholder="/lien/"></label><label>Visibilité<select data-name="visibility"><option value="all">Tous</option><option value="logged_in">Connectés</option><option value="logged_out">Invités</option></select></label></div><div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove">×</button></div></div></template>
		</form>
		</div>
		<?php
	}
}

/** The Editor screen registers the page under this name. */
function delicat_builder_v9_menu_menu_builder_admin_page(): void {
	Delicat_Builder_V9_Menu_Builder::page();
}

endif; /* class_exists guard */
