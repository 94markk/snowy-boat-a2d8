<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI for the full-site coverage layer (RC40): settings for Mon compte,
 * Merci, 404 and Recherche, plus a coverage dashboard showing which storefront
 * surface is managed by which V9 module.
 */
final class Delicat_Builder_V9_Site_Admin {

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 32 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Site complet', 'delicat-builder-v9' ),
			__( 'Site complet', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-site',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_site',
			Delicat_Builder_V9_Site::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Site::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();

		$clean = array(
			'enabled'            => empty( $input['enabled'] ) ? 0 : 1,
			'account_style'      => empty( $input['account_style'] ) ? 0 : 1,
			'account_hero'       => empty( $input['account_hero'] ) ? 0 : 1,
			'thankyou_style'     => empty( $input['thankyou_style'] ) ? 0 : 1,
			'thankyou_message'   => sanitize_textarea_field( (string) ( $input['thankyou_message'] ?? '' ) ),
			'fourofour_enabled'  => empty( $input['fourofour_enabled'] ) ? 0 : 1,
			'fourofour_title'    => sanitize_text_field( (string) ( $input['fourofour_title'] ?? '' ) ),
			'fourofour_text'     => sanitize_textarea_field( (string) ( $input['fourofour_text'] ?? '' ) ),
			'fourofour_limit'    => min( 12, max( 4, absint( $input['fourofour_limit'] ?? 8 ) ) ),
			'fourofour_category' => sanitize_title( (string) ( $input['fourofour_category'] ?? '' ) ),
			'search_style'       => empty( $input['search_style'] ) ? 0 : 1,
			'accent_color'       => sanitize_hex_color( (string) ( $input['accent_color'] ?? '' ) ) ?: '',
		);

		Delicat_Builder_V9_Site::reset_settings_cache();

		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit( 'builder_site_settings_saved', 'notice' );
		}

		return $clean;
	}

	/**
	 * One row of the coverage dashboard.
	 */
	private static function coverage(): array {
		$core_on = class_exists( 'Delicat_Builder_V9_Core', false ) && Delicat_Builder_V9_Core::is_enabled();

		$pages_managed = 0;
		if ( class_exists( 'Delicat_Builder_V9_Pages', false ) ) {
			$managed_ids = get_posts(
				array(
					'post_type'              => 'page',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => 100,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_key'               => Delicat_Builder_V9_Pages::META_ENABLED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'             => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			$pages_managed = is_array( $managed_ids ) ? count( $managed_ids ) : 0;
		}

		$shell   = class_exists( 'Delicat_Builder_V9_Shell', false ) ? Delicat_Builder_V9_Shell::settings() : array();
		$woo     = class_exists( 'Delicat_Builder_V9_Woo_UI', false ) ? Delicat_Builder_V9_Woo_UI::settings() : array();
		$buy     = class_exists( 'Delicat_Builder_V9_Purchase_UI', false ) ? Delicat_Builder_V9_Purchase_UI::settings() : array();
		$site    = Delicat_Builder_V9_Site::settings();
		$site_on = $core_on && ! empty( $site['enabled'] );

		return array(
			array( __( 'Header / Footer (Shell)', 'delicat-builder-v9' ), $core_on && ! empty( $shell['enabled'] ), 'admin.php?page=delicat-builder-v9-shell' ),
			array( __( 'Accueil + pages Builder', 'delicat-builder-v9' ), $core_on && $pages_managed > 0, 'admin.php?page=delicat-builder-v9-editor', sprintf( /* translators: %d: count */ _n( '%d page gérée', '%d pages gérées', $pages_managed, 'delicat-builder-v9' ), $pages_managed ) ),
			array( __( 'Boutique / Catégories', 'delicat-builder-v9' ), $core_on && ! empty( $woo['enabled'] ) && ! empty( $woo['archive_enabled'] ), 'admin.php?page=delicat-builder-v9-woo' ),
			array( __( 'Fiche produit', 'delicat-builder-v9' ), $core_on && ! empty( $woo['enabled'] ) && ! empty( $woo['single_enabled'] ), 'admin.php?page=delicat-builder-v9-woo' ),
			array( __( 'Panier / Checkout', 'delicat-builder-v9' ), $core_on && ! empty( $buy['enabled'] ), 'admin.php?page=delicat-builder-v9-purchase' ),
			array( __( 'Mon compte', 'delicat-builder-v9' ), $site_on && ! empty( $site['account_style'] ), 'admin.php?page=delicat-builder-v9-site' ),
			array( __( 'Commande reçue (Merci)', 'delicat-builder-v9' ), $site_on && ! empty( $site['thankyou_style'] ), 'admin.php?page=delicat-builder-v9-site' ),
			array( __( 'Page 404', 'delicat-builder-v9' ), $site_on && ! empty( $site['fourofour_enabled'] ), 'admin.php?page=delicat-builder-v9-site' ),
			array( __( 'Recherche', 'delicat-builder-v9' ), $site_on && ! empty( $site['search_style'] ), 'admin.php?page=delicat-builder-v9-site' ),
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s = Delicat_Builder_V9_Site::settings();
		$option = Delicat_Builder_V9_Site::OPTION;
		?>
		<div class="wrap delicat-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">SITE COMPLET · RC 40 FULL SITE COVERAGE</span>
					<h1><?php esc_html_e( 'Site complet', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Couvre les dernières surfaces du site : Mon compte, page Merci, 404 intelligente et résultats de recherche. WooCommerce reste l’autorité — ce module ajoute uniquement la présentation.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<h2><?php esc_html_e( 'Couverture du site', 'delicat-builder-v9' ); ?></h2>
			<table class="widefat striped" style="max-width:760px">
				<thead><tr><th><?php esc_html_e( 'Surface', 'delicat-builder-v9' ); ?></th><th><?php esc_html_e( 'Statut', 'delicat-builder-v9' ); ?></th><th><?php esc_html_e( 'Réglages', 'delicat-builder-v9' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( self::coverage() as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( (string) $row[0] ); ?></strong><?php if ( ! empty( $row[3] ) ) : ?> <small><?php echo esc_html( (string) $row[3] ); ?></small><?php endif; ?></td>
						<td><?php echo $row[1] ? '<span style="color:#22863a;font-weight:700">✓ ' . esc_html__( 'Géré par V9', 'delicat-builder-v9' ) . '</span>' : '<span style="color:#a15c00;font-weight:700">○ ' . esc_html__( 'Non géré', 'delicat-builder-v9' ) . '</span>'; ?></td>
						<td><a href="<?php echo esc_url( admin_url( (string) $row[2] ) ); ?>"><?php esc_html_e( 'Ouvrir', 'delicat-builder-v9' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="options.php" style="margin-top:26px;max-width:760px">
				<?php settings_fields( 'delicat_builder_v9_site' ); ?>

				<h2><?php esc_html_e( 'Activation', 'delicat-builder-v9' ); ?></h2>
				<label class="delicat-switch">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Activer le module Site complet', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'OFF par défaut. Aucun coût sur les pages non couvertes : une seule détection de contexte, CSS chargé uniquement sur les surfaces actives.', 'delicat-builder-v9' ); ?></small></span>
				</label>

				<h2><?php esc_html_e( 'Mon compte', 'delicat-builder-v9' ); ?></h2>
				<label class="delicat-switch">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[account_style]" value="1" <?php checked( ! empty( $s['account_style'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Style application pour Mon compte', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Navigation en onglets, tableaux de commandes en cartes sur mobile, boutons de marque. Les endpoints WooCommerce restent inchangés.', 'delicat-builder-v9' ); ?></small></span>
				</label>
				<label class="delicat-switch">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[account_hero]" value="1" <?php checked( ! empty( $s['account_hero'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Carte de bienvenue', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Bonjour + avatar aux couleurs de la marque, sans requête supplémentaire.', 'delicat-builder-v9' ); ?></small></span>
				</label>

				<h2><?php esc_html_e( 'Page Merci (commande reçue)', 'delicat-builder-v9' ); ?></h2>
				<label class="delicat-switch">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[thankyou_style]" value="1" <?php checked( ! empty( $s['thankyou_style'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Héros de confirmation', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Badge de succès, numéro et statut de commande, message de livraison instantanée.', 'delicat-builder-v9' ); ?></small></span>
				</label>
				<div class="delicat-field">
					<label><strong><?php esc_html_e( 'Message de livraison instantanée', 'delicat-builder-v9' ); ?></strong></label>
					<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option ); ?>[thankyou_message]"><?php echo esc_textarea( (string) $s['thankyou_message'] ); ?></textarea>
				</div>

				<h2><?php esc_html_e( '404 intelligente', 'delicat-builder-v9' ); ?></h2>
				<label class="delicat-switch">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[fourofour_enabled]" value="1" <?php checked( ! empty( $s['fourofour_enabled'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Remplacer la page 404 du thème', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Recherche + boutons Accueil/Boutique + carrousel des meilleures offres. Récupère les visiteurs des anciens liens produits.', 'delicat-builder-v9' ); ?></small></span>
				</label>
				<div class="delicat-field">
					<label><strong><?php esc_html_e( 'Titre', 'delicat-builder-v9' ); ?></strong></label>
					<input class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[fourofour_title]" value="<?php echo esc_attr( (string) $s['fourofour_title'] ); ?>">
				</div>
				<div class="delicat-field">
					<label><strong><?php esc_html_e( 'Texte', 'delicat-builder-v9' ); ?></strong></label>
					<textarea class="large-text" rows="2" name="<?php echo esc_attr( $option ); ?>[fourofour_text]"><?php echo esc_textarea( (string) $s['fourofour_text'] ); ?></textarea>
				</div>
				<div class="delicat-field">
					<label><strong><?php esc_html_e( 'Produits suggérés', 'delicat-builder-v9' ); ?></strong></label>
					<input type="number" min="4" max="12" name="<?php echo esc_attr( $option ); ?>[fourofour_limit]" value="<?php echo esc_attr( (string) $s['fourofour_limit'] ); ?>">
					<small><?php esc_html_e( 'Nombre de produits populaires affichés (carrousel V9, mis en cache).', 'delicat-builder-v9' ); ?></small>
				</div>
				<div class="delicat-field">
					<label><strong><?php esc_html_e( 'Catégorie (slug, optionnel)', 'delicat-builder-v9' ); ?></strong></label>
					<input class="regular-text" type="text" name="<?php echo esc_attr( $option ); ?>[fourofour_category]" value="<?php echo esc_attr( (string) $s['fourofour_category'] ); ?>" placeholder="free-fire">
				</div>

				<h2><?php esc_html_e( 'Recherche', 'delicat-builder-v9' ); ?></h2>
				<label class="delicat-switch">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[search_style]" value="1" <?php checked( ! empty( $s['search_style'] ) ); ?>>
					<span><strong><?php esc_html_e( 'Style des résultats de recherche', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Résultats en cartes cohérentes avec la boutique.', 'delicat-builder-v9' ); ?></small></span>
				</label>

				<h2><?php esc_html_e( 'Apparence', 'delicat-builder-v9' ); ?></h2>
				<div class="delicat-field">
					<label><strong><?php esc_html_e( 'Couleur d’accent (optionnel)', 'delicat-builder-v9' ); ?></strong></label>
					<input type="color" name="<?php echo esc_attr( $option ); ?>[accent_color]" value="<?php echo esc_attr( (string) ( $s['accent_color'] ?: '#ff5a1f' ) ); ?>">
					<small><?php esc_html_e( 'Vide = orange Delicat (#ff5a1f). S’applique aux quatre surfaces.', 'delicat-builder-v9' ); ?></small>
				</div>

				<?php submit_button( __( 'Enregistrer', 'delicat-builder-v9' ) ); ?>
			</form>
		</div>
		<?php
	}
}
