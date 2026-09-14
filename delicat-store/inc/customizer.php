<?php
/**
 * Customizer: one panel, a handful of sections. Every setting's default
 * comes from ds_defaults() so there is one list to maintain.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitizers keyed by control type.
 */
function ds_sanitize_checkbox( $value ): int {
	return ( true === $value || 1 === $value || '1' === $value || 'on' === $value ) ? 1 : 0;
}

function ds_sanitize_int( $value ): int {
	return (int) $value;
}

function ds_sanitize_text( $value ): string {
	return sanitize_text_field( (string) $value );
}

function ds_sanitize_textarea( $value ): string {
	return sanitize_textarea_field( (string) $value );
}

function ds_sanitize_url( $value ): string {
	return esc_url_raw( (string) $value );
}

function ds_sanitize_slug( $value ): string {
	return sanitize_title( (string) $value );
}

function ds_sanitize_digits( $value ): string {
	return ds_digits( (string) $value );
}

/**
 * Register everything.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function ds_customize_register( WP_Customize_Manager $wp_customize ): void {
	$defaults = ds_defaults();

	$wp_customize->add_panel(
		'ds_panel',
		array(
			'title'       => 'Delicat Store',
			'description' => 'Réglages de la boutique : contact, couleurs, page d\'accueil, Bon Kliyan, FAQ et informations légales.',
			'priority'    => 10,
		)
	);

	$add = static function ( string $section, string $key, string $label, string $type, array $extra = array() ) use ( $wp_customize, $defaults ): void {
		$sanitizers = array(
			'checkbox' => 'ds_sanitize_checkbox',
			'number'   => 'ds_sanitize_int',
			'text'     => 'ds_sanitize_text',
			'textarea' => 'ds_sanitize_textarea',
			'url'      => 'ds_sanitize_url',
			'slug'     => 'ds_sanitize_slug',
			'digits'   => 'ds_sanitize_digits',
			'color'    => 'sanitize_hex_color',
			'image'    => 'ds_sanitize_url',
		);
		$wp_customize->add_setting(
			$key,
			array(
				'default'           => $defaults[ $key ] ?? '',
				'sanitize_callback' => $sanitizers[ $type ] ?? 'ds_sanitize_text',
				'transport'         => 'refresh',
			)
		);
		$args = array_merge(
			array(
				'label'   => $label,
				'section' => $section,
			),
			$extra
		);
		if ( 'color' === $type ) {
			$wp_customize->add_control( new WP_Customize_Color_Control( $wp_customize, $key, $args ) );
			return;
		}
		if ( 'image' === $type ) {
			$wp_customize->add_control( new WP_Customize_Image_Control( $wp_customize, $key, $args ) );
			return;
		}
		$args['type'] = in_array( $type, array( 'slug', 'digits' ), true ) ? 'text' : $type;
		$wp_customize->add_control( $key, $args );
	};

	/* ---- Identité & contact ---- */
	$wp_customize->add_section( 'ds_identity', array( 'title' => 'Identité & contact', 'panel' => 'ds_panel', 'priority' => 10 ) );
	$add( 'ds_identity', 'tagline', 'Phrase de pied de page', 'textarea' );
	$add( 'ds_identity', 'whatsapp', 'Numéro WhatsApp (indicatif sans +, ex. 50933111283)', 'digits' );
	$add( 'ds_identity', 'email_support', 'E-mail support (vide = e-mail WooCommerce)', 'text' );
	$add( 'ds_identity', 'telegram', 'Lien Telegram', 'url' );
	$add( 'ds_identity', 'instagram', 'Lien Instagram', 'url' );
	$add( 'ds_identity', 'facebook', 'Lien Facebook', 'url' );
	$add( 'ds_identity', 'tiktok', 'Lien TikTok', 'url' );
	$add( 'ds_identity', 'youtube', 'Lien YouTube', 'url' );
	$add( 'ds_identity', 'google_fonts', 'Charger la police Poppins (Google Fonts). Décochez pour la police système, plus rapide en 3G.', 'checkbox' );

	/* ---- Couleurs ---- */
	$wp_customize->add_section( 'ds_colors', array( 'title' => 'Couleurs & forme', 'panel' => 'ds_panel', 'priority' => 20, 'description' => 'Cinq couleurs et un rayon. Tout le reste est dérivé, y compris le mode sombre.' ) );
	$add( 'ds_colors', 'color_brand', 'Couleur de marque (boutons, liens)', 'color' );
	$add( 'ds_colors', 'color_accent', 'Couleur d\'accent (promos, badges)', 'color' );
	$add( 'ds_colors', 'color_ground', 'Fond de page', 'color' );
	$add( 'ds_colors', 'color_surface', 'Surface des cartes', 'color' );
	$add( 'ds_colors', 'color_ink', 'Texte', 'color' );
	$add( 'ds_colors', 'radius', 'Rayon des coins (px, 0–32)', 'number', array( 'input_attrs' => array( 'min' => 0, 'max' => 32, 'step' => 1 ) ) );

	/* ---- Accueil ---- */
	$wp_customize->add_section( 'ds_home', array( 'title' => 'Page d\'accueil', 'panel' => 'ds_panel', 'priority' => 30 ) );
	$add( 'ds_home', 'hero_eyebrow', 'Bandeau : sur-titre', 'text' );
	$add( 'ds_home', 'hero_title', 'Bandeau : titre', 'text' );
	$add( 'ds_home', 'hero_text', 'Bandeau : texte', 'textarea' );
	$add( 'ds_home', 'hero_image', 'Bandeau : image de fond (optionnelle)', 'image' );
	$add( 'ds_home', 'hero_search', 'Bandeau : afficher la recherche', 'checkbox' );
	$add( 'ds_home', 'show_chips', 'Afficher les catégories (pastilles)', 'checkbox' );
	$add( 'ds_home', 'show_trust', 'Afficher la bande de confiance', 'checkbox' );
	$add( 'ds_home', 'show_best', 'Afficher « Les plus achetés »', 'checkbox' );
	$add( 'ds_home', 'best_title', '« Les plus achetés » : titre', 'text' );
	$add( 'ds_home', 'best_subtitle', '« Les plus achetés » : sous-titre', 'text' );
	for ( $i = 1; $i <= 4; $i++ ) {
		$add( 'ds_home', "rail_{$i}_cat", "Rayon {$i} : slug de catégorie (vide = masqué)", 'slug' );
		$add( 'ds_home', "rail_{$i}_title", "Rayon {$i} : titre", 'text' );
		$add( 'ds_home', "rail_{$i}_subtitle", "Rayon {$i} : sous-titre", 'text' );
	}
	$add( 'ds_home', 'show_promo', 'Afficher le rayon « Promos »', 'checkbox' );
	$add( 'ds_home', 'show_steps', 'Afficher « Comment ça marche »', 'checkbox' );
	$add( 'ds_home', 'show_why', 'Afficher « Pourquoi Delicat »', 'checkbox' );
	$add( 'ds_home', 'show_kliyan', 'Afficher le classement Bon Kliyan', 'checkbox' );
	$add( 'ds_home', 'show_reviews', 'Afficher « Ils nous font confiance »', 'checkbox' );
	$add( 'ds_home', 'show_faq', 'Afficher la FAQ', 'checkbox' );
	$add( 'ds_home', 'show_cta', 'Afficher l\'appel WhatsApp', 'checkbox' );

	/* ---- Boutique ---- */
	$wp_customize->add_section( 'ds_shop', array( 'title' => 'Boutique & produits', 'panel' => 'ds_panel', 'priority' => 40 ) );
	$add( 'ds_shop', 'top_threshold', 'Badge « Top Vente » à partir de N ventes', 'number', array( 'input_attrs' => array( 'min' => 1, 'step' => 1 ) ) );
	$add( 'ds_shop', 'instant_badge', 'Badge « Livraison instantanée » sur les produits virtuels', 'checkbox' );
	$add( 'ds_shop', 'hide_qty_virtual', 'Masquer la quantité sur les produits virtuels', 'checkbox' );
	$add( 'ds_shop', 'card_button', 'Bouton « + » (ajout rapide) sur les cartes produit', 'checkbox' );
	$add( 'ds_shop', 'phone_required', 'Téléphone/WhatsApp obligatoire à la commande', 'checkbox' );

	/* ---- Bon Kliyan ---- */
	$wp_customize->add_section( 'ds_kliyan', array( 'title' => 'Programme Bon Kliyan', 'panel' => 'ds_panel', 'priority' => 50, 'description' => 'Classement hebdomadaire des clients (lundi 00h00 → dimanche 23h59, heure d\'Haïti) sur les commandes terminées.' ) );
	$add( 'ds_kliyan', 'kliyan_prize', 'Lot de la semaine', 'textarea' );
	$add( 'ds_kliyan', 'kliyan_size', 'Nombre de clients affichés', 'number', array( 'input_attrs' => array( 'min' => 1, 'max' => 20 ) ) );
	$add( 'ds_kliyan', 'kliyan_amounts', 'Afficher les montants', 'checkbox' );

	/* ---- FAQ ---- */
	$wp_customize->add_section( 'ds_faq', array( 'title' => 'FAQ (accueil)', 'panel' => 'ds_panel', 'priority' => 60 ) );
	for ( $i = 1; $i <= 6; $i++ ) {
		$add( 'ds_faq', "faq_{$i}_q", "Question {$i}", 'text' );
		$add( 'ds_faq', "faq_{$i}_a", "Réponse {$i}", 'textarea' );
	}

	/* ---- Témoignages ---- */
	$wp_customize->add_section( 'ds_reviews', array( 'title' => 'Témoignages de secours', 'panel' => 'ds_panel', 'priority' => 70, 'description' => 'Affichés tant qu\'il n\'y a pas d\'avis produits 5 étoiles approuvés dans WooCommerce.' ) );
	for ( $i = 1; $i <= 3; $i++ ) {
		$add( 'ds_reviews', "review_{$i}_name", "Nom {$i}", 'text' );
		$add( 'ds_reviews', "review_{$i}_text", "Avis {$i}", 'textarea' );
	}

	/* ---- Infos légales ---- */
	$wp_customize->add_section( 'ds_legal', array( 'title' => 'Informations légales', 'panel' => 'ds_panel', 'priority' => 80, 'description' => 'Remplissent automatiquement les pages légales (CGU, confidentialité, mentions légales…). Un champ vide apparaît surligné « à compléter » sur la page.' ) );
	$add( 'ds_legal', 'legal_company', 'Raison sociale (vide = nom du site)', 'text' );
	$add( 'ds_legal', 'legal_form', 'Forme juridique', 'text' );
	$add( 'ds_legal', 'legal_address', 'Adresse du siège', 'text' );
	$add( 'ds_legal', 'legal_tax_id', 'Numéro fiscal / patente', 'text' );
	$add( 'ds_legal', 'legal_publisher', 'Directeur de la publication', 'text' );
	$add( 'ds_legal', 'legal_host_name', 'Hébergeur : nom', 'text' );
	$add( 'ds_legal', 'legal_host_address', 'Hébergeur : adresse', 'text' );
	$add( 'ds_legal', 'legal_host_url', 'Hébergeur : site', 'url' );
	$add( 'ds_legal', 'legal_email_privacy', 'E-mail confidentialité (vide = support)', 'text' );
	$add( 'ds_legal', 'legal_email_security', 'E-mail sécurité (vide = support)', 'text' );
	$add( 'ds_legal', 'legal_updated', 'Date « dernière mise à jour » (vide = aujourd\'hui)', 'text' );
}
add_action( 'customize_register', 'ds_customize_register' );
