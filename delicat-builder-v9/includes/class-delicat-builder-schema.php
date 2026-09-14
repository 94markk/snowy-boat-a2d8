<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict allow-listed page schema.
 *
 * The builder intentionally avoids arbitrary HTML/JS/CSS fields. Every value that can
 * reach the frontend is normalized here before storage and again before render.
 */
final class Delicat_Builder_V9_Schema {
	public const MAX_SECTIONS = 40;
	public const MAX_TEXT     = 5000;
	public const MAX_LAYOUT_BYTES = 131072;

	/** RC61: default floating icons behind the hero copy — token|hex tone, one per line. */
	public const HERO_FLOAT_ICONS = "gamepad|#6a5cff\ngift|#e0407f\ntv|#22a06b\ncoins|#d9860c\nsparkles|#8b5cf6\nbolt|#1f7fd6\nheart|#ff4d91\ncrown|#f0b429";

	public static function section_types(): array {
		return array(
			'hero' => array(
				'label'       => __( 'Hero', 'delicat-builder-v9' ),
				'description' => __( 'Headline, text, CTA and optional media.', 'delicat-builder-v9' ),
			),
			'text' => array(
				'label'       => __( 'Text', 'delicat-builder-v9' ),
				'description' => __( 'Fast server-rendered heading and formatted copy.', 'delicat-builder-v9' ),
			),
			'banner' => array(
				'label'       => __( 'Banner', 'delicat-builder-v9' ),
				'description' => __( 'Promotional banner with safe internal/external link.', 'delicat-builder-v9' ),
			),
			'products' => array(
				'label'       => __( 'Product carousel', 'delicat-builder-v9' ),
				'description' => __( 'Cached WooCommerce products using the lightweight native carousel.', 'delicat-builder-v9' ),
			),
			'category_chips' => array(
				'label'       => __( 'Category chips', 'delicat-builder-v9' ),
				'description' => __( 'Scrollable category navigation like Jeux, Gift Cards, Streaming and Finance.', 'delicat-builder-v9' ),
			),
			'how_it_works' => array(
				'label'       => __( 'Comment ça marche', 'delicat-builder-v9' ),
				'description' => __( 'Three fast server-rendered process cards with icons and large step numbers.', 'delicat-builder-v9' ),
			),
			'testimonials' => array(
				'label'       => __( 'Testimonials', 'delicat-builder-v9' ),
				'description' => __( 'Native horizontal customer-review rail with no carousel JavaScript.', 'delicat-builder-v9' ),
			),
			'faq' => array(
				'label'       => __( 'FAQ', 'delicat-builder-v9' ),
				'description' => __( 'Native details/summary accordion with zero frontend JavaScript.', 'delicat-builder-v9' ),
			),
			'why_delicat' => array(
				'label'       => __( 'Pourquoi Delicat', 'delicat-builder-v9' ),
				'description' => __( 'Platform benefits plus accepted payment methods, server-rendered with no frontend JavaScript.', 'delicat-builder-v9' ),
			),
			'favorites' => array(
				'label'       => __( 'Retrouve ce que tu aimes', 'delicat-builder-v9' ),
				'description' => __( 'Customisable grid of link cards (icon, title, text, link, colour) with an optional button. Server-rendered, no JavaScript.', 'delicat-builder-v9' ),
			),
			'bon_kliyan' => array(
				'label'       => __( 'Programme Bon Kliyan', 'delicat-builder-v9' ),
				'description' => __( 'Dark loyalty panel: crown eyebrow, title, text, lime button, floating membership cards. Every text, colour, size and the visual are fields. Server-rendered, no JavaScript.', 'delicat-builder-v9' ),
			),
			'newsletter' => array(
				'label'       => __( 'Newsletter', 'delicat-builder-v9' ),
				'description' => __( 'Lightweight newsletter call-to-action. Submission stays disabled until a trusted action URL is configured.', 'delicat-builder-v9' ),
			),
			'trust_strip' => array(
				'label'       => __( 'Trust strip', 'delicat-builder-v9' ),
				'description' => __( 'Secure payments, instant delivery, support and domain trust block.', 'delicat-builder-v9' ),
			),
			'spacer' => array(
				'label'       => __( 'Spacer', 'delicat-builder-v9' ),
				'description' => __( 'Responsive spacing without extra JavaScript.', 'delicat-builder-v9' ),
			),
		);
	}

	public static function default_section( string $type ): array {
		$base = array(
			'id'         => wp_generate_uuid4(),
			'type'       => $type,
			'visibility' => array(
				'desktop' => true,
				'tablet'  => true,
				'mobile'  => true,
			),
			'spacing'    => array(
				'desktop' => 24,
				'tablet'  => 20,
				'mobile'  => 16,
			),
			'max_width'  => '1200',
			'background' => '',
		);

		switch ( $type ) {
			case 'hero':
				$base['content'] = array(
					'eyebrow'         => '',
					'title'           => __( 'Build a faster page', 'delicat-builder-v9' ),
					'highlight_text'  => '',
					'text'            => '',
					'button_text'     => '',
					'button_url'      => '',
					'style'           => 'neo_glass_pro',
					'benefits'        => "bolt|Livraison rapide\nshield|Paiement sécurisé\nheadset|Support humain",
					'image_id'        => 0,
					'align'           => 'left',
					'mobile_image_id' => 0,
					'media_position'  => 'right',
					'background_image_id'        => 0,
					'mobile_background_image_id' => 0,
					'background_overlay'         => 46,
					'background_position'        => 'center',
					'search_enabled'             => 1,
					'search_placeholder'         => __( 'Rechercher un jeu, une carte ou un service…', 'delicat-builder-v9' ),
					'search_index_limit'         => 60,
					'search_results_limit'       => 5,
					'search_min_chars'           => 2,
					'min_height'      => 320,
					'float_enabled'   => 1,
					'float_icons'     => self::HERO_FLOAT_ICONS,
					'float_size'      => 52,
					'float_size_m'    => 58,
					'float_opacity'   => 62,
					'float_speed'     => 7,
					'pattern_enabled' => 1,
					'background_style' => 'mesh',
					'bg_start'        => '#eceeff',
					'bg_mid'          => '#f6ecff',
					'bg_end'          => '#ffe9f0',
					'glow_1'          => '#7d6cff',
					'glow_2'          => '#ff4d91',
					'glow_3'          => '#28c8ff',
					'glow_4'          => '#ffb547',
					'glow_strength'   => 36,
				);
				break;

			case 'text':
				$base['content'] = array(
					'title' => __( 'Section title', 'delicat-builder-v9' ),
					'text'  => '',
					'align' => 'left',
				);
				break;

			case 'banner':
				$base['content'] = array(
					'title'       => __( 'Promotion', 'delicat-builder-v9' ),
					'text'        => '',
					'button_text' => '',
					'button_url'  => '',
					'image_id'    => 0,
				);
				break;

			case 'products':
				$base['content'] = array(
					'eyebrow'        => '',
					'title'          => __( 'Popular products', 'delicat-builder-v9' ),
					'display_variant'=> 'standard',
					'heading_level'  => 'h2',
					'title_size_d'   => 0,
					'title_size_t'   => 0,
					'title_size_m'   => 0,
					'title_color'    => '',
					'subtitle'       => '',
					'subtitle_color' => '',
					'gap_mode'       => 'global',
					'card_gap_d'     => 0,
					'card_gap_t'     => 0,
					'card_gap_m'     => 0,
					'section_gap_d'  => 38,
					'section_gap_t'  => 34,
					'section_gap_m'  => 30,
					'product_name_d' => 0,
					'product_name_t' => 0,
					'product_name_m' => 0,
					'media_title_d'  => 30,
					'media_title_t'  => 25,
					'media_title_m'  => 20,
					'badge_mode'     => 'auto',
					'tag_text'       => '',
					'badge_bg'       => '',
					'badge_text'     => '',
					'status_badge_mode' => 'auto',
					'status_new_days'   => 30,
					'status_sales_min'  => 10,
					'pagination_mode'   => 'pages',
					'heart_mode'     => 'auto',
					'heart_bg'       => '',
					'heart_color'    => '',
					'heart_icon'     => 'inherit',
					'view_all_text'  => __( 'Voir tout', 'delicat-builder-v9' ),
					'view_all_url'   => '',
					'category'       => '',
					'product_ids'    => '',
					'limit'          => 12,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'style'          => 'delicat_jeux',
					'show_price'     => true,
					'show_stock'     => true,
					'show_cta'       => true,
					'progressive'    => true,
				);
				break;

			case 'category_chips':
				$base['content'] = array(
					'style'        => 'rail',
					'eyebrow'      => '',
					'title'        => '',
					'subtitle'     => '',
					'items'        => "gamepad|Jeux|/product-category/jeux/\ngift|Gift Cards|/product-category/gift-card/\ntv|Streaming|/product-category/abonnement/\ncoins|Finance|/product-category/exchange/\nstore|Shop|/shop/",
					'active_index' => 0,
				);
				break;

			case 'how_it_works':
				$base['content'] = array(
					'style'          => 'ultra',
					'eyebrow'        => 'Simple comme bonjour',
					'eyebrow_color'  => '#6a5cff',
					'title'          => 'Comment ça marche',
					'subtitle'       => 'Trois étapes. Moins d’une minute d’attente.',
					'ultra_bg'       => '#f4f3ff',
					'ultra_border'   => '#e6e4fb',
					'ultra_title'    => '#111827',
					'ultra_text'     => '#6b7280',
					'ultra_icon_bg'  => '#ffffff',
					'ultra_icon'     => '#6a5cff',
					'ultra_number'   => '#dcdfe8',
					'ultra_radius'   => 14,
					'title_color'    => '',
					'subtitle_color' => '',
					'title_size_d'   => 34,
					'title_size_t'   => 30,
					'title_size_m'   => 26,
					'card_bg'        => '#0a0a23',
					'card_border'    => '#25264d',
					'card_title'     => '#f8f8ff',
					'card_text'      => '#989bad',
					'accent_1'       => '#5964ff',
					'accent_2'       => '#ee5abd',
					'accent_3'       => '#56d8f1',
					'card_gap'       => 18,
					'card_radius'    => 30,
					'number_opacity' => 7,
					'steps'          => "store|Choisis ton pack|Sélectionne ton jeu, ton abonnement ou ta carte cadeau et le montant souhaité.\ncard|Confirme ta commande|Ajoute ton identifiant puis paie avec ton portefeuille, MonCash ou Natcash.\nbolt|Reçois instantanément|Ta recharge ou ton code est livré automatiquement après confirmation.",
				);
				break;

			case 'testimonials':
				$base['content'] = array(
					'title'          => 'ILS NOUS FONT CONFIANCE',
					'subtitle'       => 'Des milliers de recharges livrées partout en Haïti.',
					'title_color'    => '',
					'subtitle_color' => '',
					'title_size_d'   => 38,
					'title_size_t'   => 36,
					'title_size_m'   => 28,
					'card_bg'        => '#0a0a23',
					'card_border'    => '#25264d',
					'quote_color'    => '#5964ff',
					'text_color'     => '#989bad',
					'name_color'     => '#f8f8ff',
					'stars_color'    => '#ffbf2f',
					'card_gap'       => 24,
					'card_width_d'   => 560,
					'card_width_m'   => 76,
					'rating_value'   => '4.8',
					'rating_count'   => 5,
					'rating_label'   => 'AVIS VÉRIFIÉS',
					'review_title'   => 'DÉJÀ CLIENT ? LAISSE TON AVIS',
					'review_text'    => 'Ton retour aide toute la communauté Delicat Store.',
					'review_button'  => 'LAISSER UN AVIS',
					'review_url'     => 'https://wa.me/50933111283',
					'google_review_url' => '',
					'google_button'  => 'Noter sur Google',
					'link_products'  => 1,
					'items'          => "Recharge Free Fire reçue en moins de 2 minutes. Service rapide et sérieux, je commande chaque semaine.|JEAN-MARC|PORT-AU-PRINCE|5\nMon abonnement a été livré très rapidement et le support m’a répondu sur WhatsApp.|MIKAËLL|CAP-HAÏTIEN|5",
				);
				break;

			case 'faq':
				$base['content'] = array(
					'title'          => 'QUESTIONS FRÉQUENTES ❓',
					'subtitle'       => 'Tout ce qu’il faut savoir avant de commander.',
					'title_color'    => '',
					'subtitle_color' => '',
					'title_size_d'   => 38,
					'title_size_t'   => 36,
					'title_size_m'   => 28,
					'card_bg'        => '#0a0a23',
					'card_border'    => '#25264d',
					'question_color' => '#f8f8ff',
					'answer_color'   => '#989bad',
					'accent_color'   => '#5964ff',
					'card_gap'       => 14,
					'card_radius'    => 28,
					'open_index'     => 0,
					'items'          => "Combien de temps prend une recharge ?|Le délai dépend du produit. Les recharges automatiques sont généralement livrées rapidement après confirmation, tandis que les commandes manuelles affichent leur délai avant l’achat.\nQuels moyens de paiement acceptez-vous ?|MonCash, transfert bancaire, PayPal, Wise et cartes prépayées. Les prix affichés sont en gourdes (G).\nEst-ce risqué pour mon compte de jeu ?|Nous utilisons uniquement les informations nécessaires à la livraison. Ne transmettez jamais un mot de passe au support ; si une fiche produit exige exceptionnellement une information sensible, utilisez uniquement son champ sécurisé dédié.\nComment recevoir mon code de carte cadeau ?|Après confirmation du paiement, le code est livré dans votre historique de commande selon le produit acheté.",
				);
				break;

			case 'why_delicat':
				$base['content'] = array(
					'style'            => 'ultra',
					'eyebrow'          => 'Pourquoi Delicat',
					'title'            => 'Tout est pensé pour ta tranquillité',
					'subtitle'         => 'Une plateforme rapide, des informations claires et des commandes contrôlées.',
					'ultra_bg'         => '#ffffff',
					'ultra_border'     => '#e8e6f7',
					'ultra_title'      => '#111827',
					'ultra_text'       => '#6b7280',
					'ultra_radius'     => 14,
					'cta_eyebrow'      => 'Haïti · Diaspora',
					'cta_icon'         => 'globe',
					'cta_title'        => 'Fais plaisir à un proche, simplement.',
					'cta_text'         => 'Jeux, abonnements et cartes cadeaux : choisis le service, règle en toute sécurité, puis suis la livraison dans ton espace client.',
					'cta_button'       => 'Découvrir les services',
					'cta_url'          => '/shop/',
					'cta_bg_start'     => '#ecebff',
					'cta_bg_end'       => '#fde7f0',
					'cta_button_start' => '#5b5bd6',
					'cta_button_end'   => '#e0407f',
					'title_size_d'     => 34,
					'title_size_t'     => 30,
					'title_size_m'     => 26,
					'eyebrow_color'    => '#6a64ff',
					'title_color'      => '#f8f8ff',
					'card_bg'          => '#0a0a23',
					'card_border'      => '#25264d',
					'card_title'       => '#f8f8ff',
					'card_text'        => '#989bad',
					'card_radius'      => 28,
					'card_gap'         => 14,
					'items'            => "bolt|Livraison vérifiée|Chaque commande est validée avant son envoi numérique.\nshield|Paiement protégé|Prix, stock et paiement sont contrôlés côté serveur.\nglobe|Haïti & diaspora|Recharge pour toi ou pour un proche, où que tu sois.\nheadset|Support humain|Une équipe disponible pour t’accompagner 7j/7.",
					'payment_title'    => 'MOYENS DE PAIEMENT ACCEPTÉS',
					'payments'         => "phone|MonCash\nwallet|Natcash\ncard|Visa\ncard|Mastercard",
					'payment_note'     => 'Paiement chiffré — confirmation instantanée par email ou WhatsApp.',
				);
				break;

			case 'favorites':
				$base['content'] = array(
					'eyebrow'        => 'Tes essentiels',
					'title'          => 'Retrouve ce que tu aimes',
					'subtitle'       => 'Jeux, abonnements, cartes cadeaux et services : tout est là, en un geste.',
					'align'          => 'left',
					'eyebrow_color'  => '#6a5cff',
					'title_color'    => '#111827',
					'subtitle_color' => '#6b7280',
					'title_size_d'   => 34,
					'title_size_t'   => 30,
					'title_size_m'   => 26,
					'card_bg'        => '#ffffff',
					'card_border'    => '#e8e6f7',
					'card_title'     => '#111827',
					'card_text'      => '#6b7280',
					'card_radius'    => 14,
					'card_gap'       => 12,
					'columns_d'      => 4,
					'columns_m'      => 2,
					'icon_size'      => 44,
					'items'          => "gamepad|Jeux|Free Fire, PUBG, Mobile Legends…|/product-category/jeux/|#6a5cff\ntv|Streaming|Netflix, Spotify, Canal+…|/product-category/abonnement/|#e0407f\ngift|Gift Cards|iTunes, Google Play, PlayStation…|/product-category/gift-card/|#22a06b\ncoins|Exchange|MonCash, Natcash, transferts…|/product-category/exchange/|#d9860c",
					'button_text'    => 'Voir toute la boutique',
					'button_url'     => '/shop/',
					'button_start'   => '#5b5bd6',
					'button_end'     => '#e0407f',
				);
				break;

			case 'bon_kliyan':
				$base['content'] = array(
					'layout'            => 'auto',
					'align'             => 'center',
					'eyebrow'           => 'Programme Bon Kliyan',
					'eyebrow_icon'      => 'crown',
					'title'             => 'Bon kliyan merite plis.',
					'subtitle'          => 'Découvrez les avantages pensés pour remercier les clients fidèles de Delicat Store.',
					'button_text'       => 'Découvrir le programme',
					'button_url'        => '/bon-kliyan/',
					'button_2_text'     => '',
					'button_2_url'      => '',
					'perks'             => '',
					'title_weight'      => 600,
					'title_size_d'      => 46,
					'title_size_t'      => 40,
					'title_size_m'      => 34,
					'panel_bg'          => '#12131f',
					'panel_border'      => '#2a2b45',
					'panel_radius'      => 30,
					'glow_color'        => '#6a4dff',
					'glow_strength'     => 60,
					'pattern_enabled'   => 1,
					'eyebrow_color'     => '#d6ff3f',
					'title_color'       => '#ffffff',
					'subtitle_color'    => '#a9adc4',
					'button_bg'         => '#d6ff3f',
					'button_text_color' => '#12131f',
					'visual'            => 'cards',
					'visual_image_id'   => 0,
					'card_eyebrow'      => 'Programme',
					'card_title'        => 'Bon Kliyan',
					'card_icon'         => 'crown',
					'card_bg'           => '#1b1c2e',
					'card_text_color'   => '#ffffff',
					'card_accent'       => '#d6ff3f',
					'card_back_text'    => 'Delicat',
					'card_back_bg'      => '#6f5cff',
					'badge_enabled'     => 1,
					'badge_icon'        => 'star',
					'rings_enabled'     => 1,
					'motion_enabled'    => 1,
				);
				break;

			case 'newsletter':
				$base['content'] = array(
					'icon'            => 'mail',
					'title'           => 'NE MANQUEZ AUCUNE OFFRE',
					'text'            => 'Promos sur les recharges, cartes cadeaux et abonnements — directement dans votre boîte mail.',
					'placeholder'     => 'votre@email.com',
					'button_text'     => "S'ABONNER",
					'action_url'      => '',
					'panel_bg'        => '#0a0a23',
					'panel_border'    => '#25264d',
					'title_color'     => '#f8f8ff',
					'text_color'      => '#989bad',
					'accent_start'    => '#5964ff',
					'accent_end'      => '#ee3ba8',
					'card_radius'     => 28,
				);
				break;

			case 'trust_strip':
				$base['content'] = array(
					'panel_bg'       => '#0a0a23',
					'panel_border'   => '#25264d',
					'title_color'    => '#f8f8ff',
					'subtitle_color' => '#989bad',
					'accent_1'       => '#5964ff',
					'accent_2'       => '#56d8f1',
					'accent_3'       => '#ee5abd',
					'items'          => "shield|Paiements sécurisés|100% fiables\nlightning|Livraison rapide|Délai selon le produit\nheadset|Support humain|Disponible pour vous aider",
					'domain_label'   => 'delicastoreha.com',
					'domain_url'     => 'https://delicastoreha.com',
					'domain_status'  => '#2fa968',
					'footer_text'    => 'Delicat Store – Votre destination #1 pour tous vos besoins de top-up et cartes cadeaux.',
					'card_radius'    => 28,
				);
				break;

			case 'spacer':
				$base['content'] = array(
					'height_desktop' => 36,
					'height_tablet'  => 28,
					'height_mobile'  => 20,
				);
				break;
		}

		return $base;
	}

	public static function sanitize_layout( $layout ): array {
		if ( is_string( $layout ) ) {
			$decoded = json_decode( $layout, true, 32 );
			$layout  = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $layout ) ) {
			return array();
		}

		$allowed_types = array_keys( self::section_types() );
		$clean         = array();

		foreach ( array_slice( array_values( $layout ), 0, self::MAX_SECTIONS ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				continue;
			}

			$default = self::default_section( $type );
			$item    = array(
				'id'         => self::sanitize_id( (string) ( $section['id'] ?? '' ) ),
				'type'       => $type,
				'visibility' => self::sanitize_visibility( $section['visibility'] ?? array() ),
				'spacing'    => self::sanitize_spacing( $section['spacing'] ?? array() ),
				'max_width'  => self::sanitize_width( (string) ( $section['max_width'] ?? $default['max_width'] ) ),
				'background' => self::sanitize_color( (string) ( $section['background'] ?? '' ) ),
			);

			$content = is_array( $section['content'] ?? null ) ? $section['content'] : array();
			$item['content'] = self::sanitize_content( $type, $content );
			$clean[] = $item;
		}

		return $clean;
	}

	private static function sanitize_id( string $id ): string {
		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', $id );
		if ( strlen( $id ) < 8 || strlen( $id ) > 64 ) {
			return wp_generate_uuid4();
		}
		return $id;
	}

	private static function sanitize_visibility( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'desktop' => ! isset( $value['desktop'] ) || self::truthy( $value['desktop'] ),
			'tablet'  => ! isset( $value['tablet'] ) || self::truthy( $value['tablet'] ),
			'mobile'  => ! isset( $value['mobile'] ) || self::truthy( $value['mobile'] ),
		);
	}

	private static function truthy( $value ): bool {
		return in_array( $value, array( true, 1, '1', 'true', 'on', 'yes' ), true );
	}

	private static function sanitize_spacing( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'desktop' => self::clamp_int( $value['desktop'] ?? 24, 0, 160 ),
			'tablet'  => self::clamp_int( $value['tablet'] ?? 20, 0, 140 ),
			'mobile'  => self::clamp_int( $value['mobile'] ?? 16, 0, 120 ),
		);
	}

	private static function sanitize_width( string $width ): string {
		$allowed = array( '960', '1080', '1200', '1320', '1440', 'full' );
		return in_array( $width, $allowed, true ) ? $width : '1200';
	}

	private static function sanitize_color( string $color ): string {
		if ( '' === trim( $color ) ) {
			return '';
		}
		$hex = sanitize_hex_color( trim( $color ) );
		return $hex ?: '';
	}

	private static function sanitize_content( string $type, array $content ): array {
		switch ( $type ) {
			case 'hero':
				$hero_style = sanitize_key( (string) ( $content['style'] ?? 'neo_glass_pro' ) );
				if ( ! in_array( $hero_style, array( 'neo_glass_pro', 'minimal', 'solid' ), true ) ) {
					$hero_style = 'neo_glass_pro';
				}
				$background_position = sanitize_key( (string) ( $content['background_position'] ?? 'center' ) );
				if ( ! in_array( $background_position, array( 'center', 'top', 'bottom', 'left', 'right' ), true ) ) {
					$background_position = 'center';
				}
				return array(
					'eyebrow'          => self::plain( $content['eyebrow'] ?? '', 120 ),
					'title'            => self::plain( $content['title'] ?? '', 180 ),
					'highlight_text'   => self::plain( $content['highlight_text'] ?? '', 100 ),
					'text'             => self::rich( $content['text'] ?? '' ),
					'button_text'      => self::plain( $content['button_text'] ?? '', 80 ),
					'button_url'       => self::url( $content['button_url'] ?? '' ),
					'style'            => $hero_style,
					'benefits'         => self::multiline( $content['benefits'] ?? "bolt|Livraison rapide\nshield|Paiement sécurisé\nheadset|Support humain", 1200 ),
					'image_id'         => absint( $content['image_id'] ?? 0 ),
					'align'            => self::align( $content['align'] ?? 'left' ),
					'mobile_image_id'  => absint( $content['mobile_image_id'] ?? 0 ),
					'media_position'   => self::hero_media_position( $content['media_position'] ?? 'right' ),
					'background_image_id'        => absint( $content['background_image_id'] ?? 0 ),
					'mobile_background_image_id' => absint( $content['mobile_background_image_id'] ?? 0 ),
					'background_overlay'         => self::clamp_int( $content['background_overlay'] ?? 46, 0, 85 ),
					'background_position'        => $background_position,
					'search_enabled'             => ! isset( $content['search_enabled'] ) || self::truthy( $content['search_enabled'] ),
					'search_placeholder'         => self::plain( $content['search_placeholder'] ?? __( 'Rechercher un jeu, une carte ou un service…', 'delicat-builder-v9' ), 120 ),
					'search_index_limit'         => self::clamp_int( $content['search_index_limit'] ?? 60, 10, 120 ),
					'search_results_limit'       => self::clamp_int( $content['search_results_limit'] ?? 5, 3, 8 ),
					'search_min_chars'           => self::clamp_int( $content['search_min_chars'] ?? 2, 1, 4 ),
					'min_height'       => self::clamp_int( $content['min_height'] ?? 320, 220, 760 ),
					'float_enabled'    => ! isset( $content['float_enabled'] ) || self::truthy( $content['float_enabled'] ),
					'float_icons'      => self::multiline( $content['float_icons'] ?? self::HERO_FLOAT_ICONS, 600 ),
					'float_size'       => self::clamp_int( $content['float_size'] ?? 52, 28, 96 ),
					'float_size_m'     => self::clamp_int( $content['float_size_m'] ?? 58, 28, 96 ),
					'float_opacity'    => self::clamp_int( $content['float_opacity'] ?? 62, 10, 100 ),
					'float_speed'      => self::clamp_int( $content['float_speed'] ?? 7, 3, 16 ),
					'pattern_enabled'  => ! isset( $content['pattern_enabled'] ) || self::truthy( $content['pattern_enabled'] ),
					'background_style' => 'glass' === sanitize_key( (string) ( $content['background_style'] ?? 'mesh' ) ) ? 'glass' : 'mesh',
					'bg_start'         => self::sanitize_color( (string) ( $content['bg_start'] ?? '#eceeff' ) ),
					'bg_mid'           => self::sanitize_color( (string) ( $content['bg_mid'] ?? '#f6ecff' ) ),
					'bg_end'           => self::sanitize_color( (string) ( $content['bg_end'] ?? '#ffe9f0' ) ),
					'glow_1'           => self::sanitize_color( (string) ( $content['glow_1'] ?? '#7d6cff' ) ),
					'glow_2'           => self::sanitize_color( (string) ( $content['glow_2'] ?? '#ff4d91' ) ),
					'glow_3'           => self::sanitize_color( (string) ( $content['glow_3'] ?? '#28c8ff' ) ),
					'glow_4'           => self::sanitize_color( (string) ( $content['glow_4'] ?? '#ffb547' ) ),
					'glow_strength'    => self::clamp_int( $content['glow_strength'] ?? 36, 0, 100 ),
				);

			case 'text':
				return array(
					'title' => self::plain( $content['title'] ?? '', 180 ),
					'text'  => self::rich( $content['text'] ?? '' ),
					'align' => self::align( $content['align'] ?? 'left' ),
				);

			case 'banner':
				return array(
					'title'       => self::plain( $content['title'] ?? '', 180 ),
					'text'        => self::rich( $content['text'] ?? '' ),
					'button_text' => self::plain( $content['button_text'] ?? '', 80 ),
					'button_url'  => self::url( $content['button_url'] ?? '' ),
					'image_id'    => absint( $content['image_id'] ?? 0 ),
				);

			case 'products':
				$orderby = sanitize_key( (string) ( $content['orderby'] ?? 'date' ) );
				$order   = strtoupper( (string) ( $content['order'] ?? 'DESC' ) );
				$gap_mode = sanitize_key( (string) ( $content['gap_mode'] ?? '' ) );
				if ( ! in_array( $gap_mode, array( 'global', 'custom' ), true ) ) {
					// Backward compatibility: RC10/RC11 sections had no mode.
					// If a non-zero per-carousel gap was saved, treat it as a
					// deliberate custom value. Otherwise inherit global.
					$gap_mode = (
						absint( $content['card_gap_d'] ?? 0 ) > 0
						|| absint( $content['card_gap_t'] ?? 0 ) > 0
						|| absint( $content['card_gap_m'] ?? 0 ) > 0
					) ? 'custom' : 'global';
				}
				return array(
					'eyebrow'        => self::plain( $content['eyebrow'] ?? '', 80 ),
					'title'          => self::plain( $content['title'] ?? '', 180 ),
					'display_variant'=> in_array( $content['display_variant'] ?? '', array( 'standard', 'ranking' ), true ) ? $content['display_variant'] : 'standard',
					'heading_level'  => in_array( $content['heading_level'] ?? '', array( 'h1', 'h2', 'h3' ), true ) ? $content['heading_level'] : 'h2',
					'title_size_d'   => self::clamp_int( $content['title_size_d'] ?? 0, 0, 64 ),
					'title_size_t'   => self::clamp_int( $content['title_size_t'] ?? 0, 0, 56 ),
					'title_size_m'   => self::clamp_int( $content['title_size_m'] ?? 0, 0, 48 ),
					'title_color'    => sanitize_hex_color( (string) ( $content['title_color'] ?? '' ) ) ?: '',
					'subtitle'       => self::plain( $content['subtitle'] ?? '', 240 ),
					'subtitle_color' => sanitize_hex_color( (string) ( $content['subtitle_color'] ?? '' ) ) ?: '',
					'gap_mode'       => $gap_mode,
					'card_gap_d'     => self::clamp_int( $content['card_gap_d'] ?? 0, 0, 48 ),
					'card_gap_t'     => self::clamp_int( $content['card_gap_t'] ?? 0, 0, 40 ),
					'card_gap_m'     => self::clamp_int( $content['card_gap_m'] ?? 0, 0, 32 ),
					'section_gap_d'  => self::clamp_int( $content['section_gap_d'] ?? 38, 0, 120 ),
					'section_gap_t'  => self::clamp_int( $content['section_gap_t'] ?? 34, 0, 100 ),
					'section_gap_m'  => self::clamp_int( $content['section_gap_m'] ?? 30, 0, 90 ),
					'product_name_d' => self::clamp_int( $content['product_name_d'] ?? 0, 0, 36 ),
					'product_name_t' => self::clamp_int( $content['product_name_t'] ?? 0, 0, 32 ),
					'product_name_m' => self::clamp_int( $content['product_name_m'] ?? 0, 0, 28 ),
					'media_title_d'  => self::clamp_int( $content['media_title_d'] ?? 30, 14, 52 ),
					'media_title_t'  => self::clamp_int( $content['media_title_t'] ?? 25, 14, 44 ),
					'media_title_m'  => self::clamp_int( $content['media_title_m'] ?? 20, 12, 36 ),
					'badge_mode'     => class_exists( 'Delicat_Builder_V9_Badge_Engine' )
						? Delicat_Builder_V9_Badge_Engine::sanitize_mode( $content['badge_mode'] ?? 'auto' )
						: 'auto',
					'tag_text'       => self::plain( $content['tag_text'] ?? '', 40 ),
					'badge_bg'       => sanitize_hex_color( (string) ( $content['badge_bg'] ?? '' ) ) ?: '',
					'badge_text'     => sanitize_hex_color( (string) ( $content['badge_text'] ?? '' ) ) ?: '',
					'status_badge_mode' => in_array( $content['status_badge_mode'] ?? '', array( 'auto', 'sale', 'new', 'bestseller', 'off' ), true ) ? $content['status_badge_mode'] : 'auto',
					'status_new_days'   => self::clamp_int( $content['status_new_days'] ?? 30, 1, 120 ),
					'status_sales_min'  => self::clamp_int( $content['status_sales_min'] ?? 10, 1, 10000 ),
					'pagination_mode'   => in_array( $content['pagination_mode'] ?? '', array( 'pages', 'cards' ), true ) ? $content['pagination_mode'] : 'pages',
					'heart_mode'     => in_array( $content['heart_mode'] ?? '', array( 'auto', 'on', 'off' ), true ) ? $content['heart_mode'] : 'auto',
					'heart_bg'       => sanitize_hex_color( (string) ( $content['heart_bg'] ?? '' ) ) ?: '',
					'heart_color'    => sanitize_hex_color( (string) ( $content['heart_color'] ?? '' ) ) ?: '',
					'heart_icon'     => in_array( $content['heart_icon'] ?? '', array( 'inherit', 'heart', 'heart_outline', 'star', 'bolt' ), true ) ? $content['heart_icon'] : 'inherit',
					'view_all_text'  => self::plain( $content['view_all_text'] ?? '', 60 ),
					'view_all_url'   => self::url( $content['view_all_url'] ?? '' ),
					'category'       => sanitize_title( (string) ( $content['category'] ?? '' ) ),
					'product_ids'    => self::product_ids( $content['product_ids'] ?? '' ),
					'limit'          => self::clamp_int( $content['limit'] ?? 12, 1, 24 ),
					'orderby'        => in_array( $orderby, array( 'date', 'title', 'menu_order', 'modified', 'rand', 'popularity' ), true ) ? $orderby : 'date',
					'order'          => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
					'style'          => self::carousel_style( $content['style'] ?? 'delicat_jeux' ),
					'show_price'     => ! isset( $content['show_price'] ) || self::truthy( $content['show_price'] ),
					'show_stock'     => ! isset( $content['show_stock'] ) || self::truthy( $content['show_stock'] ),
					'show_cta'       => ! isset( $content['show_cta'] ) || self::truthy( $content['show_cta'] ),
					'progressive'    => ! isset( $content['progressive'] ) || self::truthy( $content['progressive'] ),
				);

			case 'how_it_works':
				return array(
					'style'          => in_array( (string) ( $content['style'] ?? 'ultra' ), array( 'ultra', 'neon' ), true ) ? (string) ( $content['style'] ?? 'ultra' ) : 'ultra',
					'eyebrow'        => self::plain( $content['eyebrow'] ?? 'Simple comme bonjour', 120 ),
					'eyebrow_color'  => self::sanitize_color( (string) ( $content['eyebrow_color'] ?? '#6a5cff' ) ),
					'ultra_bg'       => self::sanitize_color( (string) ( $content['ultra_bg'] ?? '#f4f3ff' ) ),
					'ultra_border'   => self::sanitize_color( (string) ( $content['ultra_border'] ?? '#e6e4fb' ) ),
					'ultra_title'    => self::sanitize_color( (string) ( $content['ultra_title'] ?? '#111827' ) ),
					'ultra_text'     => self::sanitize_color( (string) ( $content['ultra_text'] ?? '#6b7280' ) ),
					'ultra_icon_bg'  => self::sanitize_color( (string) ( $content['ultra_icon_bg'] ?? '#ffffff' ) ),
					'ultra_icon'     => self::sanitize_color( (string) ( $content['ultra_icon'] ?? '#6a5cff' ) ),
					'ultra_number'   => self::sanitize_color( (string) ( $content['ultra_number'] ?? '#dcdfe8' ) ),
					'ultra_radius'   => self::clamp_int( $content['ultra_radius'] ?? 14, 8, 48 ),
					'title'          => self::plain( $content['title'] ?? '', 180 ),
					'subtitle'       => self::plain( $content['subtitle'] ?? '', 240 ),
					'title_color'    => self::sanitize_color( (string) ( $content['title_color'] ?? '' ) ),
					'subtitle_color' => self::sanitize_color( (string) ( $content['subtitle_color'] ?? '' ) ),
					'title_size_d'   => self::clamp_int( $content['title_size_d'] ?? 38, 18, 64 ),
					'title_size_t'   => self::clamp_int( $content['title_size_t'] ?? 36, 18, 56 ),
					'title_size_m'   => self::clamp_int( $content['title_size_m'] ?? 28, 18, 48 ),
					'card_bg'        => self::sanitize_color( (string) ( $content['card_bg'] ?? '#0a0a23' ) ),
					'card_border'    => self::sanitize_color( (string) ( $content['card_border'] ?? '#25264d' ) ),
					'card_title'     => self::sanitize_color( (string) ( $content['card_title'] ?? '#f8f8ff' ) ),
					'card_text'      => self::sanitize_color( (string) ( $content['card_text'] ?? '#989bad' ) ),
					'accent_1'       => self::sanitize_color( (string) ( $content['accent_1'] ?? '#5964ff' ) ),
					'accent_2'       => self::sanitize_color( (string) ( $content['accent_2'] ?? '#ee5abd' ) ),
					'accent_3'       => self::sanitize_color( (string) ( $content['accent_3'] ?? '#56d8f1' ) ),
					'card_gap'       => self::clamp_int( $content['card_gap'] ?? 18, 0, 48 ),
					'card_radius'    => self::clamp_int( $content['card_radius'] ?? 30, 8, 48 ),
					'number_opacity' => self::clamp_int( $content['number_opacity'] ?? 7, 0, 30 ),
					'steps'          => self::multiline( $content['steps'] ?? '', 6000 ),
				);

			case 'testimonials':
				return array(
					'title'          => self::plain( $content['title'] ?? '', 180 ),
					'subtitle'       => self::plain( $content['subtitle'] ?? '', 240 ),
					'title_color'    => self::sanitize_color( (string) ( $content['title_color'] ?? '' ) ),
					'subtitle_color' => self::sanitize_color( (string) ( $content['subtitle_color'] ?? '' ) ),
					'title_size_d'   => self::clamp_int( $content['title_size_d'] ?? 38, 18, 64 ),
					'title_size_t'   => self::clamp_int( $content['title_size_t'] ?? 36, 18, 56 ),
					'title_size_m'   => self::clamp_int( $content['title_size_m'] ?? 28, 18, 48 ),
					'card_bg'        => self::sanitize_color( (string) ( $content['card_bg'] ?? '#0a0a23' ) ),
					'card_border'    => self::sanitize_color( (string) ( $content['card_border'] ?? '#25264d' ) ),
					'quote_color'    => self::sanitize_color( (string) ( $content['quote_color'] ?? '#5964ff' ) ),
					'text_color'     => self::sanitize_color( (string) ( $content['text_color'] ?? '#989bad' ) ),
					'name_color'     => self::sanitize_color( (string) ( $content['name_color'] ?? '#f8f8ff' ) ),
					'stars_color'    => self::sanitize_color( (string) ( $content['stars_color'] ?? '#ffbf2f' ) ),
					'card_gap'       => self::clamp_int( $content['card_gap'] ?? 24, 0, 48 ),
					'card_width_d'   => self::clamp_int( $content['card_width_d'] ?? 560, 280, 760 ),
					'card_width_m'   => self::clamp_int( $content['card_width_m'] ?? 76, 68, 92 ),
					'rating_value'   => self::plain( $content['rating_value'] ?? '4.8', 8 ),
					'rating_count'   => self::clamp_int( $content['rating_count'] ?? 5, 0, 100000 ),
					'rating_label'   => self::plain( $content['rating_label'] ?? 'AVIS VÉRIFIÉS', 80 ),
					'review_title'   => self::plain( $content['review_title'] ?? '', 160 ),
					'review_text'    => self::plain( $content['review_text'] ?? '', 240 ),
					'review_button'  => self::plain( $content['review_button'] ?? '', 80 ),
					'review_url'     => self::url( $content['review_url'] ?? '' ),
					'google_review_url' => self::url( $content['google_review_url'] ?? '' ),
					'google_button'  => self::plain( $content['google_button'] ?? 'Noter sur Google', 60 ),
					'link_products'  => ! isset( $content['link_products'] ) || self::truthy( $content['link_products'] ),
					'items'          => self::multiline( $content['items'] ?? '', 8000 ),
				);

			case 'faq':
				return array(
					'title'          => self::plain( $content['title'] ?? '', 180 ),
					'subtitle'       => self::plain( $content['subtitle'] ?? '', 240 ),
					'title_color'    => self::sanitize_color( (string) ( $content['title_color'] ?? '' ) ),
					'subtitle_color' => self::sanitize_color( (string) ( $content['subtitle_color'] ?? '' ) ),
					'title_size_d'   => self::clamp_int( $content['title_size_d'] ?? 38, 18, 64 ),
					'title_size_t'   => self::clamp_int( $content['title_size_t'] ?? 36, 18, 56 ),
					'title_size_m'   => self::clamp_int( $content['title_size_m'] ?? 28, 18, 48 ),
					'card_bg'        => self::sanitize_color( (string) ( $content['card_bg'] ?? '#0a0a23' ) ),
					'card_border'    => self::sanitize_color( (string) ( $content['card_border'] ?? '#25264d' ) ),
					'question_color' => self::sanitize_color( (string) ( $content['question_color'] ?? '#f8f8ff' ) ),
					'answer_color'   => self::sanitize_color( (string) ( $content['answer_color'] ?? '#989bad' ) ),
					'accent_color'   => self::sanitize_color( (string) ( $content['accent_color'] ?? '#5964ff' ) ),
					'card_gap'       => self::clamp_int( $content['card_gap'] ?? 14, 0, 40 ),
					'card_radius'    => self::clamp_int( $content['card_radius'] ?? 28, 8, 48 ),
					'open_index'     => self::clamp_int( $content['open_index'] ?? 0, 0, 20 ),
					'items'          => self::multiline( $content['items'] ?? '', 10000 ),
				);

			case 'why_delicat':
				return array(
					'style'            => in_array( (string) ( $content['style'] ?? 'ultra' ), array( 'ultra', 'neon' ), true ) ? (string) ( $content['style'] ?? 'ultra' ) : 'ultra',
					'subtitle'         => self::plain( $content['subtitle'] ?? '', 240 ),
					'ultra_bg'         => self::sanitize_color( (string) ( $content['ultra_bg'] ?? '#ffffff' ) ),
					'ultra_border'     => self::sanitize_color( (string) ( $content['ultra_border'] ?? '#e8e6f7' ) ),
					'ultra_title'      => self::sanitize_color( (string) ( $content['ultra_title'] ?? '#111827' ) ),
					'ultra_text'       => self::sanitize_color( (string) ( $content['ultra_text'] ?? '#6b7280' ) ),
					'ultra_radius'     => self::clamp_int( $content['ultra_radius'] ?? 14, 8, 48 ),
					'cta_eyebrow'      => self::plain( $content['cta_eyebrow'] ?? '', 80 ),
					'cta_icon'         => sanitize_key( (string) ( $content['cta_icon'] ?? 'globe' ) ),
					'cta_title'        => self::plain( $content['cta_title'] ?? '', 160 ),
					'cta_text'         => self::plain( $content['cta_text'] ?? '', 400 ),
					'cta_button'       => self::plain( $content['cta_button'] ?? '', 60 ),
					'cta_url'          => self::url( $content['cta_url'] ?? '' ),
					'cta_bg_start'     => self::sanitize_color( (string) ( $content['cta_bg_start'] ?? '#ecebff' ) ),
					'cta_bg_end'       => self::sanitize_color( (string) ( $content['cta_bg_end'] ?? '#fde7f0' ) ),
					'cta_button_start' => self::sanitize_color( (string) ( $content['cta_button_start'] ?? '#5b5bd6' ) ),
					'cta_button_end'   => self::sanitize_color( (string) ( $content['cta_button_end'] ?? '#e0407f' ) ),
					'eyebrow'          => self::plain( $content['eyebrow'] ?? '', 120 ),
					'title'            => self::plain( $content['title'] ?? '', 220 ),
					'title_size_d'     => self::clamp_int( $content['title_size_d'] ?? 38, 18, 64 ),
					'title_size_t'     => self::clamp_int( $content['title_size_t'] ?? 36, 18, 56 ),
					'title_size_m'     => self::clamp_int( $content['title_size_m'] ?? 28, 18, 48 ),
					'eyebrow_color'    => self::sanitize_color( (string) ( $content['eyebrow_color'] ?? '#6a64ff' ) ),
					'title_color'      => self::sanitize_color( (string) ( $content['title_color'] ?? '#f8f8ff' ) ),
					'card_bg'          => self::sanitize_color( (string) ( $content['card_bg'] ?? '#0a0a23' ) ),
					'card_border'      => self::sanitize_color( (string) ( $content['card_border'] ?? '#25264d' ) ),
					'card_title'       => self::sanitize_color( (string) ( $content['card_title'] ?? '#f8f8ff' ) ),
					'card_text'        => self::sanitize_color( (string) ( $content['card_text'] ?? '#989bad' ) ),
					'card_radius'      => self::clamp_int( $content['card_radius'] ?? 28, 8, 48 ),
					'card_gap'         => self::clamp_int( $content['card_gap'] ?? 14, 0, 40 ),
					'items'            => self::multiline( $content['items'] ?? '', 10000 ),
					'payment_title'    => self::plain( $content['payment_title'] ?? '', 140 ),
					'payments'         => self::multiline( $content['payments'] ?? '', 3000 ),
					'payment_note'     => self::plain( $content['payment_note'] ?? '', 300 ),
				);

			case 'favorites':
				return array(
					'eyebrow'        => self::plain( $content['eyebrow'] ?? '', 120 ),
					'title'          => self::plain( $content['title'] ?? '', 180 ),
					'subtitle'       => self::plain( $content['subtitle'] ?? '', 240 ),
					'align'          => in_array( (string) ( $content['align'] ?? 'left' ), array( 'left', 'center' ), true ) ? (string) $content['align'] : 'left',
					'eyebrow_color'  => self::sanitize_color( (string) ( $content['eyebrow_color'] ?? '#6a5cff' ) ),
					'title_color'    => self::sanitize_color( (string) ( $content['title_color'] ?? '#111827' ) ),
					'subtitle_color' => self::sanitize_color( (string) ( $content['subtitle_color'] ?? '#6b7280' ) ),
					'title_size_d'   => self::clamp_int( $content['title_size_d'] ?? 38, 18, 64 ),
					'title_size_t'   => self::clamp_int( $content['title_size_t'] ?? 36, 18, 56 ),
					'title_size_m'   => self::clamp_int( $content['title_size_m'] ?? 28, 18, 48 ),
					'card_bg'        => self::sanitize_color( (string) ( $content['card_bg'] ?? '#ffffff' ) ),
					'card_border'    => self::sanitize_color( (string) ( $content['card_border'] ?? '#e8e6f7' ) ),
					'card_title'     => self::sanitize_color( (string) ( $content['card_title'] ?? '#111827' ) ),
					'card_text'      => self::sanitize_color( (string) ( $content['card_text'] ?? '#6b7280' ) ),
					'card_radius'    => self::clamp_int( $content['card_radius'] ?? 14, 8, 48 ),
					'card_gap'       => self::clamp_int( $content['card_gap'] ?? 12, 0, 40 ),
					'columns_d'      => self::clamp_int( $content['columns_d'] ?? 4, 2, 4 ),
					'columns_m'      => self::clamp_int( $content['columns_m'] ?? 2, 1, 2 ),
					'icon_size'      => self::clamp_int( $content['icon_size'] ?? 44, 36, 80 ),
					'items'          => self::multiline( $content['items'] ?? '', 8000 ),
					'button_text'    => self::plain( $content['button_text'] ?? '', 60 ),
					'button_url'     => self::url( $content['button_url'] ?? '' ),
					'button_start'   => self::sanitize_color( (string) ( $content['button_start'] ?? '#5b5bd6' ) ),
					'button_end'     => self::sanitize_color( (string) ( $content['button_end'] ?? '#e0407f' ) ),
				);

			case 'bon_kliyan':
				$bk_layout = sanitize_key( (string) ( $content['layout'] ?? 'auto' ) );
				$bk_visual = sanitize_key( (string) ( $content['visual'] ?? 'cards' ) );
				$bk_weight = absint( $content['title_weight'] ?? 600 );
				return array(
					'layout'            => in_array( $bk_layout, array( 'auto', 'stack' ), true ) ? $bk_layout : 'auto',
					'align'             => self::align( $content['align'] ?? 'center' ),
					'eyebrow'           => self::plain( $content['eyebrow'] ?? '', 80 ),
					'eyebrow_icon'      => self::plain( $content['eyebrow_icon'] ?? 'crown', 24 ),
					'title'             => self::plain( $content['title'] ?? '', 160 ),
					'subtitle'          => self::plain( $content['subtitle'] ?? '', 320 ),
					'button_text'       => self::plain( $content['button_text'] ?? '', 60 ),
					'button_url'        => self::url( $content['button_url'] ?? '' ),
					'button_2_text'     => self::plain( $content['button_2_text'] ?? '', 60 ),
					'button_2_url'      => self::url( $content['button_2_url'] ?? '' ),
					'perks'             => self::multiline( $content['perks'] ?? '', 2400 ),
					'title_weight'      => in_array( $bk_weight, array( 400, 500, 600, 700, 800 ), true ) ? $bk_weight : 600,
					'title_size_d'      => self::clamp_int( $content['title_size_d'] ?? 46, 20, 72 ),
					'title_size_t'      => self::clamp_int( $content['title_size_t'] ?? 40, 20, 64 ),
					'title_size_m'      => self::clamp_int( $content['title_size_m'] ?? 34, 18, 52 ),
					'panel_bg'          => self::sanitize_color( (string) ( $content['panel_bg'] ?? '#12131f' ) ),
					'panel_border'      => self::sanitize_color( (string) ( $content['panel_border'] ?? '#2a2b45' ) ),
					'panel_radius'      => self::clamp_int( $content['panel_radius'] ?? 30, 8, 48 ),
					'glow_color'        => self::sanitize_color( (string) ( $content['glow_color'] ?? '#6a4dff' ) ),
					'glow_strength'     => self::clamp_int( $content['glow_strength'] ?? 60, 0, 100 ),
					'pattern_enabled'   => ! isset( $content['pattern_enabled'] ) || self::truthy( $content['pattern_enabled'] ),
					'eyebrow_color'     => self::sanitize_color( (string) ( $content['eyebrow_color'] ?? '#d6ff3f' ) ),
					'title_color'       => self::sanitize_color( (string) ( $content['title_color'] ?? '#ffffff' ) ),
					'subtitle_color'    => self::sanitize_color( (string) ( $content['subtitle_color'] ?? '#a9adc4' ) ),
					'button_bg'         => self::sanitize_color( (string) ( $content['button_bg'] ?? '#d6ff3f' ) ),
					'button_text_color' => self::sanitize_color( (string) ( $content['button_text_color'] ?? '#12131f' ) ),
					'visual'            => in_array( $bk_visual, array( 'cards', 'image', 'none' ), true ) ? $bk_visual : 'cards',
					'visual_image_id'   => absint( $content['visual_image_id'] ?? 0 ),
					'card_eyebrow'      => self::plain( $content['card_eyebrow'] ?? '', 40 ),
					'card_title'        => self::plain( $content['card_title'] ?? '', 40 ),
					'card_icon'         => self::plain( $content['card_icon'] ?? 'crown', 24 ),
					'card_bg'           => self::sanitize_color( (string) ( $content['card_bg'] ?? '#1b1c2e' ) ),
					'card_text_color'   => self::sanitize_color( (string) ( $content['card_text_color'] ?? '#ffffff' ) ),
					'card_accent'       => self::sanitize_color( (string) ( $content['card_accent'] ?? '#d6ff3f' ) ),
					'card_back_text'    => self::plain( $content['card_back_text'] ?? '', 40 ),
					'card_back_bg'      => self::sanitize_color( (string) ( $content['card_back_bg'] ?? '#6f5cff' ) ),
					'badge_enabled'     => ! isset( $content['badge_enabled'] ) || self::truthy( $content['badge_enabled'] ),
					'badge_icon'        => self::plain( $content['badge_icon'] ?? 'star', 24 ),
					'rings_enabled'     => ! isset( $content['rings_enabled'] ) || self::truthy( $content['rings_enabled'] ),
					'motion_enabled'    => ! isset( $content['motion_enabled'] ) || self::truthy( $content['motion_enabled'] ),
				);

			case 'newsletter':
				return array(
					'icon'            => sanitize_key( (string) ( $content['icon'] ?? 'mail' ) ),
					'title'           => self::plain( $content['title'] ?? '', 180 ),
					'text'            => self::plain( $content['text'] ?? '', 360 ),
					'placeholder'     => self::plain( $content['placeholder'] ?? 'votre@email.com', 120 ),
					'button_text'     => self::plain( $content['button_text'] ?? '', 80 ),
					'action_url'      => self::url( $content['action_url'] ?? '' ),
					'panel_bg'        => self::sanitize_color( (string) ( $content['panel_bg'] ?? '#0a0a23' ) ),
					'panel_border'    => self::sanitize_color( (string) ( $content['panel_border'] ?? '#25264d' ) ),
					'title_color'     => self::sanitize_color( (string) ( $content['title_color'] ?? '#f8f8ff' ) ),
					'text_color'      => self::sanitize_color( (string) ( $content['text_color'] ?? '#989bad' ) ),
					'accent_start'    => self::sanitize_color( (string) ( $content['accent_start'] ?? '#5964ff' ) ),
					'accent_end'      => self::sanitize_color( (string) ( $content['accent_end'] ?? '#ee3ba8' ) ),
					'card_radius'     => self::clamp_int( $content['card_radius'] ?? 28, 8, 48 ),
				);

			case 'trust_strip':
				return array(
					'panel_bg'       => self::sanitize_color( (string) ( $content['panel_bg'] ?? '#0a0a23' ) ),
					'panel_border'   => self::sanitize_color( (string) ( $content['panel_border'] ?? '#25264d' ) ),
					'title_color'    => self::sanitize_color( (string) ( $content['title_color'] ?? '#f8f8ff' ) ),
					'subtitle_color' => self::sanitize_color( (string) ( $content['subtitle_color'] ?? '#989bad' ) ),
					'accent_1'       => self::sanitize_color( (string) ( $content['accent_1'] ?? '#5964ff' ) ),
					'accent_2'       => self::sanitize_color( (string) ( $content['accent_2'] ?? '#56d8f1' ) ),
					'accent_3'       => self::sanitize_color( (string) ( $content['accent_3'] ?? '#ee5abd' ) ),
					'items'          => self::multiline( $content['items'] ?? '', 4000 ),
					'domain_label'   => self::plain( $content['domain_label'] ?? '', 120 ),
					'domain_url'     => self::url( $content['domain_url'] ?? '' ),
					'domain_status'  => self::sanitize_color( (string) ( $content['domain_status'] ?? '#2fa968' ) ),
					'footer_text'    => self::plain( $content['footer_text'] ?? '', 360 ),
					'card_radius'    => self::clamp_int( $content['card_radius'] ?? 28, 8, 48 ),
				);

			case 'category_chips':
				return array(
					'style'        => in_array( $content['style'] ?? '', array( 'rail', 'grid' ), true ) ? $content['style'] : 'rail',
					'eyebrow'      => self::plain( $content['eyebrow'] ?? '', 80 ),
					'title'        => self::plain( $content['title'] ?? '', 140 ),
					'subtitle'     => self::plain( $content['subtitle'] ?? '', 220 ),
					'items'        => self::multiline( $content['items'] ?? '', 3000 ),
					'active_index' => self::clamp_int( $content['active_index'] ?? 0, -1, 20 ),
				);

			case 'spacer':
				return array(
					'height_desktop' => self::clamp_int( $content['height_desktop'] ?? 36, 0, 240 ),
					'height_tablet'  => self::clamp_int( $content['height_tablet'] ?? 28, 0, 200 ),
					'height_mobile'  => self::clamp_int( $content['height_mobile'] ?? 20, 0, 160 ),
				);
		}

		return array();
	}

	private static function plain( $value, int $max ): string {
		$value = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function multiline( $value, int $max ): string {
		$value = sanitize_textarea_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	private static function rich( $value ): string {
		$value = (string) $value;
		if ( strlen( $value ) > self::MAX_TEXT * 4 ) {
			$value = substr( $value, 0, self::MAX_TEXT * 4 );
		}
		$allowed = array(
			'br'     => array(),
			'p'      => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'a'      => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
		);
		$clean = wp_kses( $value, $allowed );
		return function_exists( 'wp_targeted_link_rel' ) ? wp_targeted_link_rel( $clean ) : $clean;
	}

	private static function url( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		return esc_url_raw( $value, array( 'http', 'https' ) );
	}

	private static function product_ids( $value ): string {
		$parts = preg_split( '/[^0-9]+/', (string) $value );
		$ids = array();
		foreach ( (array) $parts as $part ) {
			$id = absint( $part );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= 24 ) {
				break;
			}
		}
		return implode( ',', $ids );
	}

	private static function carousel_style( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'delicat_jeux', 'delicat_abonnement' ), true ) ? $value : 'delicat_jeux';
	}

	private static function hero_media_position( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'right', 'left' ), true ) ? $value : 'right';
	}

	private static function align( $value ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'left', 'center' ), true ) ? $value : 'left';
	}

	private static function clamp_int( $value, int $min, int $max ): int {
		$value = absint( $value );
		return max( $min, min( $max, $value ) );
	}
}
