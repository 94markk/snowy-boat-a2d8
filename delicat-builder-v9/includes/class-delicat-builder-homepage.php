<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe homepage starter for staging pages.
 *
 * This class never changes the WordPress front-page setting. It only prepares
 * a selected Page for Delicat Builder output and keeps the original post
 * content untouched underneath for rollback.
 */
final class Delicat_Builder_V9_Homepage {
	public const BACKUP_META = '_delicat_builder_v9_homepage_starter_backup';
	public const MANAGED_META = '_delicat_builder_v9_homepage_managed';

	public static function boot(): void {
		// Reserved for future non-admin homepage hooks. RC6 is admin driven.
	}

	private static function product_id_by_candidates( array $slugs, array $names = array() ): int {
		foreach ( $slugs as $slug ) {
			$post = get_page_by_path( sanitize_title( $slug ), OBJECT, 'product' );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				return (int) $post->ID;
			}
		}

		if ( ! empty( $names ) ) {
			$posts = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => 40,
					's'              => sanitize_text_field( (string) $names[0] ),
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $posts as $post_id ) {
				$title = strtolower( wp_strip_all_tags( get_the_title( $post_id ) ) );
				foreach ( $names as $name ) {
					if ( strtolower( wp_strip_all_tags( (string) $name ) ) === $title ) {
						return absint( $post_id );
					}
				}
			}
		}

		return 0;
	}

	private static function ids( array $definitions ): array {
		$ids = array();
		foreach ( $definitions as $definition ) {
			$id = self::product_id_by_candidates(
				(array) ( $definition['slugs'] ?? array() ),
				(array) ( $definition['names'] ?? array() )
			);
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	public static function detected_products(): array {
		return array(
			'games' => self::ids(
				array(
					array( 'slugs' => array( 'blood-strike-2', 'blood-strike' ), 'names' => array( 'Blood Strike', 'BLOOD STRIKE' ) ),
					array( 'slugs' => array( 'dream-league-2026', 'dls-2026' ), 'names' => array( 'DLS 2026' ) ),
					array( 'slugs' => array( 'free-fire-latam-2', 'free-fire-latam' ), 'names' => array( 'Free Fire (LATAM)', 'Free Fire LATAM' ) ),
					array( 'slugs' => array( 'pubg-mobile-auto', 'pubg-mobile' ), 'names' => array( 'PUBG Mobile' ) ),
					array( 'slugs' => array( 'flexcity', 'flexcity-rp' ), 'names' => array( 'FLEXCITY RP', 'Flexcity RP' ) ),
				)
			),
			'subscriptions' => self::ids(
				array(
					array( 'slugs' => array( 'netflix' ), 'names' => array( 'Netflix' ) ),
					array( 'slugs' => array( 'crunchyroll' ), 'names' => array( 'Crunchyroll' ) ),
					array( 'slugs' => array( 'prime-video' ), 'names' => array( 'Prime Video' ) ),
					array( 'slugs' => array( 'hbo-max' ), 'names' => array( 'HBO MAX', 'HBO Max' ) ),
					array( 'slugs' => array( 'spotify-us' ), 'names' => array( 'Spotify US' ) ),
				)
			),
			'exchange' => self::ids(
				array(
					array( 'slugs' => array( 'meru' ), 'names' => array( 'Meru' ) ),
					array( 'slugs' => array( 'paypal' ), 'names' => array( 'PayPal', 'Paypal' ) ),
					array( 'slugs' => array( 'wise' ), 'names' => array( 'WISE', 'Wise' ) ),
				)
			),
			'gift_cards' => self::ids(
				array(
					array( 'slugs' => array( 'app-store-itunes-us', 'apple-gift-card-us', 'apple-gift-card' ), 'names' => array( 'Apple Gift card (US)', 'Apple gift card' ) ),
					array( 'slugs' => array( 'roblox-global', 'roblox' ), 'names' => array( 'Roblox (Global)', 'Roblox' ) ),
					array( 'slugs' => array( 'playstation-us', 'playstation-gift-card', 'playstation' ), 'names' => array( 'PlayStation (US)', 'PlayStation gift card' ) ),
					array( 'slugs' => array( 'netflix-gift-card-us', 'netflix-gift-card' ), 'names' => array( 'Netflix Gift Card (US)', 'Netflix Gift Card' ) ),
				)
			),
		);
	}

	private static function term_link( array $slugs, string $fallback ): string {
		if ( taxonomy_exists( 'product_cat' ) ) {
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
				if ( $term instanceof WP_Term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						return (string) $link;
					}
				}
			}
		}
		return home_url( $fallback );
	}

	private static function term_product_count( array $slugs ): int {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', sanitize_title( $slug ), 'product_cat' );
			if ( $term instanceof WP_Term ) {
				return max( 0, absint( $term->count ) );
			}
		}
		return 0;
	}

	private static function section( string $id, string $type, array $content, int $space = 8 ): array {
		return array(
			'id'         => $id,
			'type'       => $type,
			'visibility' => array( 'desktop' => true, 'tablet' => true, 'mobile' => true ),
			'spacing'    => array( 'desktop' => $space, 'tablet' => $space, 'mobile' => max( 4, $space - 2 ) ),
			'max_width'  => '1200',
			'background' => '',
			'content'    => $content,
		);
	}

	public static function is_managed_page( int $page_id ): bool {
		return $page_id > 0 && (
			(bool) get_post_meta( $page_id, self::MANAGED_META, true )
			|| (bool) get_post_meta( $page_id, self::BACKUP_META, true )
		);
	}

	private static function configure_design_for_page( int $page_id, bool $starter_defaults = false ): void {
		$current = Delicat_Builder_V9_Design::settings();
		$required = array(
			'enabled'             => 1,
			'scope'               => 'selected_page',
			'selected_page_id'    => $page_id,
			'carousel_cta'        => 1,
			'carousel_tag'        => 1,
			'carousel_bubble'     => 0,
			'heart_engine'        => 1,
			'heart_guest'         => 1,
			'carousel_pagination' => 1,
		);

		$defaults = $starter_defaults
			? array(
				'preset'              => 'modern_store',
				'carousel_style'      => 'default',
				'homepage_mode'       => 'light',
				'homepage_background' => '#ffffff',
				'homepage_heading'    => '#111827',
				'homepage_subtitle'   => '#667085',
				'homepage_view_bg'    => '#151d48',
				'homepage_view_text'  => '#ffffff',
				'homepage_title_d'    => 32,
				'homepage_title_t'    => 30,
				'homepage_title_m'    => 28,
				'carousel_shell'      => 0,
				'compact_mobile'      => 1,
			)
			: array();

		$design = array_merge( $current, $defaults, $required );
		$design = Delicat_Builder_V9_Design_Admin::sanitize( $design );
		update_option( Delicat_Builder_V9_Design::OPTION, $design, false );
		Delicat_Builder_V9_Design::reset_settings_cache();
	}


	private static function reference_product_defaults( array $content, string $section_id ): array {
		$defaults = array(
			'title_size_d'      => 34,
			'title_size_t'      => 31,
			'title_size_m'      => 27,
			'gap_mode'          => 'custom',
			'card_gap_d'        => 18,
			'card_gap_t'        => 16,
			'card_gap_m'        => 12,
			'section_gap_d'     => 62,
			'section_gap_t'     => 54,
			'section_gap_m'     => 44,
			'product_name_d'    => 18,
			'product_name_t'    => 17,
			'product_name_m'    => 14,
			'media_title_d'     => 30,
			'media_title_t'     => 26,
			'media_title_m'     => 18,
			'badge_mode'        => 'auto',
			'status_badge_mode' => 'auto',
			'status_new_days'   => 30,
			'status_sales_min'  => 10,
			'pagination_mode'   => 'cards',
			'heart_mode'        => 'auto',
			'progressive'       => true,
			'show_price'        => true,
			'show_stock'        => false,
			'show_cta'          => true,
		);

		switch ( $section_id ) {
			case 'home-best-sellers-carousel':
				$defaults['eyebrow'] = 'CLASSEMENT';
				$defaults['heading_level'] = 'h1';
				$defaults['display_variant'] = 'ranking';
				$defaults['title_size_d'] = 38;
				$defaults['title_size_t'] = 36;
				$defaults['title_size_m'] = 29;
				$defaults['title'] = 'Les plus achetés';
				$defaults['subtitle'] = 'Classement des produits les plus commandés.';
				$defaults['tag_text'] = '';
				$defaults['style'] = 'delicat_jeux';
				$defaults['orderby'] = 'popularity';
				$defaults['order'] = 'DESC';
				// The ranking rail already communicates popularity through its position.
				// Keep the artwork clean instead of stacking a second TOP VENTE pill.
				$defaults['status_badge_mode'] = 'off';
				$defaults['heart_mode'] = 'off';
				$defaults['show_cta'] = false;
				break;
			case 'home-jeux-carousel':
				$defaults['heading_level'] = 'h2';
				$defaults['title'] = 'JEUX';
				$defaults['subtitle'] = 'Recharge automatique sur votre compte.';
				$defaults['tag_text'] = 'Jeux';
				$defaults['style'] = 'delicat_jeux';
				break;
			case 'home-abonnement-carousel':
				$defaults['heading_level'] = 'h2';
				$defaults['title'] = 'ABONNEMENT PREMIUM';
				$defaults['subtitle'] = 'Vos plateformes préférées, à portée de main.';
				$defaults['tag_text'] = 'Streaming';
				$defaults['style'] = 'delicat_abonnement';
				break;
			case 'home-exchange-carousel':
				$defaults['heading_level'] = 'h2';
				$defaults['title'] = 'ÉCHANGES';
				$defaults['subtitle'] = 'PayPal, Wise, Meru et services financiers.';
				$defaults['tag_text'] = 'Finance';
				$defaults['style'] = 'delicat_jeux';
				break;
			case 'home-gift-card-carousel':
				$defaults['heading_level'] = 'h2';
				$defaults['title'] = 'GIFT CARDS';
				$defaults['subtitle'] = 'Cartes cadeaux officielles, codes instantanés.';
				$defaults['tag_text'] = 'GIFT CARD';
				$defaults['style'] = 'delicat_abonnement';
				break;
		}

		/*
		 * RC48: this merges defaults OVER content (`array_merge( $content, $defaults )`
		 * — the later array wins), which is correct for the design fields this
		 * function exists to impose, but it also silently discarded copy the
		 * merchant had written. Re-applying the reference reset every section's
		 * title, subtitle, eyebrow and badge text back to the shipped strings,
		 * with no warning and no undo. Design is the reference's to set; words
		 * are not. Any authored, non-empty copy now survives the merge.
		 */
		$legacy_reference_copy = array(
			'home-jeux-carousel' => array(
				'title'    => array( 'JEUX 🎮', 'JEUX 🎮️', 'JEUX 🕹', 'JEUX 🕹️' ),
				'tag_text' => array( '🎮 JEUX', '🎮 Jeux', '🕹 JEUX', '🕹 Jeux' ),
			),
			'home-abonnement-carousel' => array(
				'tag_text' => array( '▶ STREAMING', '▶ Streaming', '▶️ STREAMING', '▶️ Streaming', '📺 Streaming' ),
			),
			'home-exchange-carousel' => array(
				'title'    => array( 'ÉCHANGES & SERVICES 💳', 'ÉCHANGES 💵', 'ECHANGES 💵', 'ÉCHANGES 💳' ),
				'tag_text' => array( '💳 FINANCE', '💳 Finance', '💰 FINANCE', '💰 Finance', '💵 Finance' ),
			),
			'home-gift-card-carousel' => array(
				'tag_text' => array( '🎁 Gift Card', '🎁 GIFT CARD' ),
			),
		);
		foreach ( array( 'title', 'subtitle', 'eyebrow', 'tag_text' ) as $authored ) {
			if ( ! isset( $content[ $authored ] ) || '' === trim( (string) $content[ $authored ] ) ) {
				continue;
			}
			$legacy_values = $legacy_reference_copy[ $section_id ][ $authored ] ?? array();
			$stored = trim( preg_replace( '/[\x{FE0F}]/u', '', (string) $content[ $authored ] ) );
			$is_legacy = false;
			foreach ( $legacy_values as $legacy_value ) {
				if ( $stored === trim( preg_replace( '/[\x{FE0F}]/u', '', (string) $legacy_value ) ) ) {
					$is_legacy = true;
					break;
				}
			}
			if ( ! $is_legacy ) {
				unset( $defaults[ $authored ] );
			}
		}

		return array_merge( $content, $defaults );
	}

private static function reference_hero_section(): array {
	$shop_url = function_exists( 'wc_get_page_permalink' )
		? wc_get_page_permalink( 'shop' )
		: home_url( '/shop/' );

	return self::section(
		'home-premium-hero',
		'hero',
		array(
			'eyebrow'         => 'DELICAT STORE · RAPIDE · SÉCURISÉ',
			'title'           => 'Votre univers digital, livré rapidement.',
			'highlight_text'  => 'livré rapidement.',
			'text'            => 'Jeux, gift cards, streaming et services numériques avec une expérience simple, sécurisée et pensée pour Haïti.',
			'button_text'     => 'Explorer la boutique',
			'button_url'      => $shop_url,
			'style'           => 'neo_glass_pro',
			'benefits'        => "bolt|Livraison rapide\nshield|Paiement sécurisé\nheadset|Support humain",
			'image_id'        => 0,
			'mobile_image_id' => 0,
			'align'           => 'left',
			'media_position'  => 'right',
			'min_height'      => 280,
		),
		8
	);
}

	private static function reference_best_sellers_section(): array {
		return self::section(
			'home-best-sellers-carousel',
			'products',
			array(
				'eyebrow'           => 'CLASSEMENT',
				'title'             => 'Les plus achetés',
				'display_variant'   => 'ranking',
				'heading_level'     => 'h1',
				'title_size_d'      => 0,
				'title_size_t'      => 0,
				'title_size_m'      => 0,
				'title_color'       => '',
				'subtitle'          => 'Classement des produits les plus commandés.',
				'subtitle_color'    => '',
				'view_all_text'     => 'Voir tout',
				'view_all_url'      => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
				'tag_text'          => '',
				'category'          => '',
				'product_ids'       => '',
				'limit'             => 8,
				'orderby'           => 'popularity',
				'order'             => 'DESC',
				'style'             => 'delicat_jeux',
				'gap_mode'          => 'custom',
				'card_gap_d'        => 18,
				'card_gap_t'        => 16,
				'card_gap_m'        => 12,
				'section_gap_d'     => 62,
				'section_gap_t'     => 54,
				'section_gap_m'     => 44,
				'product_name_d'    => 18,
				'product_name_t'    => 17,
				'product_name_m'    => 14,
				'media_title_d'     => 30,
				'media_title_t'     => 26,
				'media_title_m'     => 18,
				'badge_mode'        => 'category',
				'status_badge_mode' => 'off',
				'status_new_days'   => 30,
				'status_sales_min'  => 10,
				'pagination_mode'   => 'cards',
				'heart_mode'        => 'off',
				'show_price'        => true,
				'show_stock'        => false,
				'show_cta'          => false,
				'progressive'       => true,
			),
			8
		);
	}

private static function reference_browse_categories_section(): array {
	$shop_url = function_exists( 'wc_get_page_permalink' )
		? wc_get_page_permalink( 'shop' )
		: home_url( '/shop/' );

	$definitions = array(
		array(
			'icon'  => 'gamepad',
			'label' => 'Jeux',
			'slugs' => array( 'jeux' ),
			'url'   => self::term_link( array( 'jeux' ), '/product-category/jeux/' ),
		),
		array(
			'icon'  => 'gift',
			'label' => 'Gift Card',
			'slugs' => array( 'gift-card', 'gift-cards' ),
			'url'   => self::term_link( array( 'gift-card', 'gift-cards' ), '/product-category/gift-card/' ),
		),
		array(
			'icon'  => 'crown',
			'label' => 'Abonnement',
			'slugs' => array( 'abonnement', 'streaming', 'abonnements' ),
			'url'   => self::term_link( array( 'abonnement', 'streaming', 'abonnements' ), '/product-category/abonnement/' ),
		),
	);

	$dls_id = self::product_id_by_candidates(
		array( 'dream-league-2026', 'dls-2026', 'dream-league' ),
		array( 'DLS 2026', 'Dream League 2026', 'Dream League' )
	);
	$dls_url = $dls_id > 0 ? get_permalink( $dls_id ) : $shop_url;
	if ( ! is_string( $dls_url ) || '' === $dls_url ) {
		$dls_url = $shop_url;
	}
	$definitions[] = array(
		'icon'  => 'sparkles',
		'label' => 'Dream League',
		'slugs' => array( 'dream-league', 'dls', 'dream-league-soccer' ),
		'url'   => $dls_url,
	);

	$lines = array();
	foreach ( $definitions as $definition ) {
		$count = self::term_product_count( (array) $definition['slugs'] );
		if ( 0 === $count && 'Dream League' === $definition['label'] && $dls_id > 0 ) {
			$count = 1;
		}
		$meta = $count > 0
			? sprintf(
				/* translators: %d: product count. */
				_n( '%d produit', '%d produits', $count, 'delicat-builder-v9' ),
				$count
			)
			: __( 'Voir les produits', 'delicat-builder-v9' );

		$lines[] = implode(
			'|',
			array(
				$definition['icon'],
				$definition['label'],
				$definition['url'],
				$meta,
			)
		);
	}

	$section = Delicat_Builder_V9_Schema::default_section( 'category_chips' );
	$section['id'] = 'home-browse-categories';
	$section['content']['style'] = 'grid';
	$section['content']['eyebrow'] = 'CATÉGORIES';
	$section['content']['title'] = 'Parcourir';
	$section['content']['subtitle'] = '';
	$section['content']['items'] = implode( "\n", $lines );
	$section['content']['active_index'] = -1;
	$section['spacing'] = array( 'desktop' => 18, 'tablet' => 16, 'mobile' => 12 );
	$section['max_width'] = '1200';
	return $section;
}

private static function reference_info_sections(): array {
	$definitions = array(
		'home-favorites'     => 'favorites',
		'home-how-it-works' => 'how_it_works',
		'home-why-delicat'   => 'why_delicat',
		'home-bon-kliyan'    => 'bon_kliyan',
		'home-testimonials'  => 'testimonials',
		'home-faq'           => 'faq',
		'home-newsletter'    => 'newsletter',
		'home-trust-strip'   => 'trust_strip',
	);
	$sections = array();

	foreach ( $definitions as $id => $type ) {
		$default = Delicat_Builder_V9_Schema::default_section( $type );
		$default['id'] = $id;
		$default['spacing'] = array( 'desktop' => 20, 'tablet' => 18, 'mobile' => 16 );
		$default['max_width'] = '1200';
		$sections[] = $default;
	}

	$sections[] = self::reference_browse_categories_section();
	$sections[] = self::reference_best_sellers_section();
	return $sections;
}

private static function ensure_reference_info_sections( array $layout ): array {
	$reference_sections = self::reference_info_sections();
	$reference_by_id = array();
	foreach ( $reference_sections as $reference ) {
		$id = sanitize_key( (string) ( $reference['id'] ?? '' ) );
		if ( '' !== $id ) {
			$reference_by_id[ $id ] = $reference;
		}
	}

	// RC28 "exact" reference action deliberately refreshes these known
	// Lovable information blocks to the current reference defaults. They remain
	// fully editable after apply. Unknown/user sections are preserved.
	$updated = array();
	$seen = array();
	foreach ( $layout as $section ) {
		if ( ! is_array( $section ) ) {
			continue;
		}
		$id = sanitize_key( (string) ( $section['id'] ?? '' ) );
		if ( in_array( $id, array( 'home-category-chips', 'home-discovery-chips' ), true ) ) {
			continue;
		}
		if ( isset( $reference_by_id[ $id ] ) ) {
			$updated[] = $reference_by_id[ $id ];
			$seen[ $id ] = true;
		} else {
			$updated[] = $section;
		}
	}

	foreach ( $reference_by_id as $id => $reference ) {
		if ( ! isset( $seen[ $id ] ) ) {
			$updated[] = $reference;
		}
	}

	// Exact screenshot flow:
	// Browse → Best sellers → Games → How it works → Subscriptions → Gift cards
	// → Exchanges → Why Delicat/payments → Reviews
	// → FAQ → Newsletter → Trust/domain end.
	$preferred = array(
		'home-browse-categories',
		'home-favorites',
		'home-best-sellers-carousel',
		'home-jeux-carousel',
		'home-how-it-works',
		'home-abonnement-carousel',
		'home-gift-card-carousel',
		'home-exchange-carousel',
		'home-why-delicat',
		'home-bon-kliyan',
		'home-testimonials',
		'home-faq',
		'home-newsletter',
		'home-trust-strip',
	);

	$by_id = array();
	$hero = array();
	$other = array();
	foreach ( $updated as $section ) {
		$id = sanitize_key( (string) ( $section['id'] ?? '' ) );
		if ( 'hero' === ( $section['type'] ?? '' ) ) {
			$hero[] = $section;
		} elseif ( in_array( $id, $preferred, true ) ) {
			$by_id[ $id ] = $section;
		} else {
			$other[] = $section;
		}
	}

	$ordered = array();
	foreach ( $preferred as $id ) {
		if ( isset( $by_id[ $id ] ) ) {
			$ordered[] = $by_id[ $id ];
		}
	}

	return array_merge( $hero, $ordered, $other );
}

public static function apply_reference_info_sections( int $page_id ) {
	if ( ! current_user_can( 'edit_post', $page_id ) || 'page' !== get_post_type( $page_id ) ) {
		return new WP_Error( 'forbidden', __( 'You cannot update this page.', 'delicat-builder-v9' ) );
	}

	self::backup_once( $page_id );
	$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
	if ( empty( $layout ) ) {
		$layout = self::build_layout( $page_id );
	}
	if ( empty( $layout ) ) {
		return new WP_Error( 'empty_layout', __( 'Could not build the homepage layout.', 'delicat-builder-v9' ) );
	}

	$layout = self::ensure_reference_info_sections( $layout );
	$layout = Delicat_Builder_V9_Schema::sanitize_layout( $layout );

	$result = Delicat_Builder_V9_Pages::save_layout( $page_id, $layout );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 1 );
	update_post_meta( $page_id, self::MANAGED_META, 1 );
	Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );
	Delicat_Builder_V9_Cache::purge_page( $page_id );

	self::identity_audit(
		'builder_homepage_reference_info_sections_applied',
		'info',
		array( 'page_id' => $page_id, 'sections' => count( $layout ) )
	);

	return self::diagnostics( $page_id );
}

	private static function configure_lovable_reference_design( int $page_id ): void {
		$current = Delicat_Builder_V9_Design::settings();
		$reference = array(
			'enabled'             => 1,
			'scope'               => 'selected_page',
			'selected_page_id'    => $page_id,
			'preset'              => 'lovable_reference',
			'homepage_mode'       => 'light',
			'homepage_background' => '#f7f8fc',
			'homepage_heading'    => '#11131f',
			'homepage_subtitle'   => '#697087',
			'homepage_view_bg'    => '#11183f',
			'homepage_view_text'  => '#ffffff',
			'homepage_title_d'    => 34,
			'homepage_title_t'    => 31,
			'homepage_title_m'    => 27,
			'carousel_gap_d'      => 18,
			'carousel_gap_t'      => 16,
			'carousel_gap_m'      => 12,
			'product_name_d'      => 18,
			'product_name_t'      => 17,
			'product_name_m'      => 14,
			'badge_bg'            => '#171733',
			'badge_text'          => '#ffffff',
			'heart_bg'            => '#1b1b3d',
			'heart_color'         => '#ff4da4',
			'heart_icon'          => 'heart',
			'carousel_cta'        => 1,
			'carousel_tag'        => 1,
			'carousel_bubble'     => 0,
			'heart_engine'        => 1,
			'heart_guest'         => 1,
			'carousel_pagination' => 1,
			'carousel_shell'      => 0,
			'compact_mobile'      => 1,
		);
		$design = array_merge( $current, $reference );
		$design = Delicat_Builder_V9_Design_Admin::sanitize( $design );
		update_option( Delicat_Builder_V9_Design::OPTION, $design, false );
		Delicat_Builder_V9_Design::reset_settings_cache();
	}

private static function transaction_snapshot( int $page_id ): array {
	return array(
		'enabled' => Delicat_Builder_V9_Pages::is_enabled_for_page( $page_id ) ? 1 : 0,
		'layout'  => get_post_meta( $page_id, Delicat_Builder_V9_Pages::META_LAYOUT, true ),
		'managed' => get_post_meta( $page_id, self::MANAGED_META, true ),
		'design'  => Delicat_Builder_V9_Design::settings(),
	);
}

private static function restore_transaction_snapshot( int $page_id, array $snapshot ): void {
	update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, empty( $snapshot['enabled'] ) ? 0 : 1 );

	if ( array_key_exists( 'layout', $snapshot ) ) {
		if ( is_array( $snapshot['layout'] ) && ! empty( $snapshot['layout'] ) ) {
			update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_LAYOUT, $snapshot['layout'] );
		} else {
			delete_post_meta( $page_id, Delicat_Builder_V9_Pages::META_LAYOUT );
		}
	}

	if ( ! empty( $snapshot['managed'] ) ) {
		update_post_meta( $page_id, self::MANAGED_META, $snapshot['managed'] );
	} else {
		delete_post_meta( $page_id, self::MANAGED_META );
	}

	if ( is_array( $snapshot['design'] ?? null ) ) {
		update_option( Delicat_Builder_V9_Design::OPTION, $snapshot['design'], false );
		Delicat_Builder_V9_Design::reset_settings_cache();
	}

	Delicat_Builder_V9_Cache::purge_page( $page_id );
}

private static function smoke_render_layout( int $page_id, array $layout, string $stage ) {
	try {
		$html = Delicat_Builder_V9_Renderer::render_layout( $layout, true );
		if ( '' === trim( $html ) ) {
			return new WP_Error(
				'empty_render',
				__( 'The Builder layout produced no safe frontend output. The previous page state was preserved.', 'delicat-builder-v9' )
			);
		}
		return true;
	} catch ( Throwable $error ) {
		if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
			Delicat_Builder_V9_Renderer::runtime_failure(
				$stage,
				$error,
				array( 'page_id' => $page_id )
			);
		}
		return new WP_Error(
			'builder_runtime_error',
			__( 'The Builder detected a frontend runtime error. The previous page state was preserved.', 'delicat-builder-v9' )
		);
	}
}

	public static function apply_lovable_reference( int $page_id ) {
		if ( ! current_user_can( 'edit_post', $page_id ) || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot update this page.', 'delicat-builder-v9' ) );
		}

		self::backup_once( $page_id );
		$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
		if ( empty( $layout ) ) {
			$layout = self::build_layout( $page_id );
		}
		if ( empty( $layout ) ) {
			return new WP_Error( 'empty_layout', __( 'Could not build the homepage reference layout.', 'delicat-builder-v9' ) );
		}

		$has_best = false;
		foreach ( $layout as &$section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$section_id = sanitize_key( (string) ( $section['id'] ?? '' ) );
			if ( 'home-best-sellers-carousel' === $section_id ) {
				$has_best = true;
			}
			if ( 'products' === ( $section['type'] ?? '' ) ) {
				$section['content'] = self::reference_product_defaults( (array) ( $section['content'] ?? array() ), $section_id );
			}
		}
		unset( $section );
		if ( ! $has_best ) {
			$layout[] = self::reference_best_sellers_section();
		}

		// RC41 reference: preserve any existing premium hero, replace old duplicate
		// category rails with Parcourir, then keep Les plus achetés as the first
		// product carousel.
		$preferred = array(
			'home-browse-categories',
			'home-best-sellers-carousel',
			'home-jeux-carousel',
			'home-abonnement-carousel',
			'home-gift-card-carousel',
			'home-exchange-carousel',
		);
		$by_id = array();
		$hero = array();
		$other = array();
		foreach ( $layout as $section ) {
			$id = sanitize_key( (string) ( $section['id'] ?? '' ) );
			if ( 'hero' === ( $section['type'] ?? '' ) ) {
				$hero[] = $section;
				continue;
			}
			if (
				'category_chips' === ( $section['type'] ?? '' )
				&& 'home-browse-categories' !== $id
			) {
				// Replace legacy duplicate rails with the richer Parcourir grid.
				continue;
			}
			if ( in_array( $id, $preferred, true ) ) {
				$by_id[ $id ] = $section;
			} else {
				$other[] = $section;
			}
		}
		$ordered = array();
		foreach ( $preferred as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}
		$layout = array_merge( $hero, $ordered, $other );
		$layout = self::ensure_reference_info_sections( $layout );

		$layout = Delicat_Builder_V9_Schema::sanitize_layout( $layout );
		$snapshot = self::transaction_snapshot( $page_id );

		// Keep the live page on its previous renderer until the replacement has
		// passed a real server-side smoke render and compiler pass.
		update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 0 );

		try {
			$smoke = self::smoke_render_layout( $page_id, $layout, 'homepage_reference_smoke' );
			if ( is_wp_error( $smoke ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $smoke;
			}

			$result = Delicat_Builder_V9_Pages::save_layout( $page_id, $layout );
			if ( is_wp_error( $result ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $result;
			}

			self::configure_lovable_reference_design( $page_id );
			Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );

			$smoke = self::smoke_render_layout( $page_id, $layout, 'homepage_reference_final_smoke' );
			if ( is_wp_error( $smoke ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $smoke;
			}

			// Enable last, only after every risky step has passed.
			update_post_meta( $page_id, self::MANAGED_META, 1 );
			update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 1 );
			Delicat_Builder_V9_Cache::purge_page( $page_id );
		} catch ( Throwable $error ) {
			self::restore_transaction_snapshot( $page_id, $snapshot );
			if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
				Delicat_Builder_V9_Renderer::runtime_failure(
					'homepage_reference_transaction',
					$error,
					array( 'page_id' => $page_id )
				);
			}
			return new WP_Error(
				'builder_transaction_failed',
				__( 'The Builder update was stopped safely because a runtime error was detected. The previous page state was restored.', 'delicat-builder-v9' )
			);
		}

		self::identity_audit(
			'builder_homepage_lovable_reference_applied',
			'info',
			array( 'page_id' => $page_id, 'sections' => count( $layout ) )
		);

		return self::diagnostics( $page_id );
	}

	public static function diagnostics( int $page_id ): array {
		$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
		$manifest = Delicat_Builder_V9_Compiler::get_manifest( $page_id );
		$post = get_post( $page_id );
		$design = Delicat_Builder_V9_Design::settings();
		$analysis = array();
		if ( ! empty( $layout ) ) {
			try {
				$analysis = Delicat_Builder_V9_Compiler::analyze_layout( $layout );
			} catch ( Throwable $error ) {
				if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
					Delicat_Builder_V9_Renderer::runtime_failure(
						'homepage_diagnostics',
						$error,
						array( 'page_id' => $page_id )
					);
				}
			}
		}

		$product_sections = 0;
		$info_sections = 0;
		foreach ( $layout as $section ) {
			if ( is_array( $section ) && 'products' === ( $section['type'] ?? '' ) ) {
				$product_sections++;
			}
			if ( is_array( $section ) && in_array( $section['type'] ?? '', array( 'favorites', 'how_it_works', 'why_delicat', 'bon_kliyan', 'testimonials', 'faq', 'newsletter', 'trust_strip' ), true ) ) {
				$info_sections++;
			}
		}

		return array(
			'enabled' => Delicat_Builder_V9_Pages::is_enabled_for_page( $page_id ),
			'managed' => self::is_managed_page( $page_id ),
			'sections' => count( $layout ),
			'product_sections' => $product_sections,
			'info_sections' => $info_sections,
			/* RC63: Google product stars need WooCommerce reviews + star ratings on. */
			'wc_reviews'    => 'yes' === (string) get_option( 'woocommerce_enable_reviews', 'yes' ) && 'yes' === (string) get_option( 'woocommerce_enable_review_rating', 'yes' ),
			'reference_preset' => 'lovable_reference' === ( $design['preset'] ?? '' ),
			'needs_carousel' => ! empty( $analysis['needs_carousel'] ),
			'component_css' => $analysis['css'] ?? array(),
			'manifest_version' => sanitize_text_field( (string) ( $manifest['version'] ?? '' ) ),
			'design_match' => ! empty( $design['enabled'] )
				&& 'selected_page' === ( $design['scope'] ?? '' )
				&& absint( $design['selected_page_id'] ?? 0 ) === $page_id,
			'legacy_shortcode' => $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'delicat_v9_carousel' ),
			'legacy_basic_carousel' => $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'delicat_product_carousel' ),
			'products_css_readable' => is_readable( DELICAT_BUILDER_V9_DIR . 'assets/components/products.css' ),
			'design_css_readable' => is_readable( DELICAT_BUILDER_V9_DIR . 'assets/css/design-studio.css' ),
		);
	}

	public static function repair( int $page_id ) {
		if ( ! current_user_can( 'edit_post', $page_id ) || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot repair this page.', 'delicat-builder-v9' ) );
		}

		$snapshot = self::transaction_snapshot( $page_id );
		self::backup_once( $page_id );

		try {
			$layout = Delicat_Builder_V9_Pages::get_layout( $page_id );
			if ( empty( $layout ) ) {
				$layout = self::build_layout( $page_id );
			}
			if ( empty( $layout ) ) {
				return new WP_Error( 'empty_layout', __( 'Could not rebuild the homepage starter.', 'delicat-builder-v9' ) );
			}

			update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 0 );

			$smoke = self::smoke_render_layout( $page_id, $layout, 'homepage_repair_smoke' );
			if ( is_wp_error( $smoke ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $smoke;
			}

			$result = Delicat_Builder_V9_Pages::save_layout( $page_id, $layout );
			if ( is_wp_error( $result ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $result;
			}

			self::configure_design_for_page( $page_id, false );
			Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );

			$smoke = self::smoke_render_layout( $page_id, $layout, 'homepage_repair_final_smoke' );
			if ( is_wp_error( $smoke ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $smoke;
			}

			update_post_meta( $page_id, self::MANAGED_META, 1 );
			update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 1 );
			Delicat_Builder_V9_Cache::purge_page( $page_id );
		} catch ( Throwable $error ) {
			self::restore_transaction_snapshot( $page_id, $snapshot );
			if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
				Delicat_Builder_V9_Renderer::runtime_failure(
					'homepage_repair_transaction',
					$error,
					array( 'page_id' => $page_id )
				);
			}
			return new WP_Error(
				'builder_repair_failed',
				__( 'Repair was stopped safely because a runtime error was detected. The previous page state was restored.', 'delicat-builder-v9' )
			);
		}

		self::identity_audit(
			'builder_homepage_layout_repaired',
			'warning',
			array( 'page_id' => $page_id, 'sections' => count( $layout ) )
		);

		return self::diagnostics( $page_id );
	}

	public static function build_layout( int $page_id ): array {
		$page = get_post( $page_id );
		if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
			return array();
		}

		$products = self::detected_products();
		$games_url = self::term_link( array( 'jeux' ), '/product-category/jeux/' );
		$gift_url = self::term_link( array( 'gift-card', 'gift-cards' ), '/product-category/gift-card/' );
		$exchange_url = self::term_link( array( 'exchange', 'exchanges' ), '/product-category/exchange/' );

		$layout = array(
			self::reference_hero_section(),
			self::reference_browse_categories_section(),
			self::reference_best_sellers_section(),
			self::section(
				'home-jeux-carousel',
				'products',
				array(
					'title'         => 'JEUX',
					'heading_level' => 'h1',
					'title_size_d'  => 0,
					'title_size_t'  => 0,
					'title_size_m'  => 0,
					'title_color'   => '',
					'subtitle'      => 'Recharge automatique sur votre compte.',
					'subtitle_color'=> '',
					'view_all_text' => 'Voir tout',
					'view_all_url'  => $games_url,
					'tag_text'      => 'Jeux',
					'category'      => 'jeux',
					'product_ids'   => implode( ',', $products['games'] ),
					'limit'         => 10,
					'orderby'       => 'menu_order',
					'order'         => 'ASC',
					'style'         => 'delicat_jeux',
					'show_price'    => true,
					'show_stock'    => false,
					'show_cta'      => true,
					'progressive'   => true,
				),
				8
			),
			self::section(
				'home-abonnement-carousel',
				'products',
				array(
					'title'         => 'ABONNEMENT PREMIUM',
					'heading_level' => 'h2',
					'title_size_d'  => 0,
					'title_size_t'  => 0,
					'title_size_m'  => 0,
					'title_color'   => '',
					'subtitle'      => 'Vos plateformes préférées, à portée de main.',
					'view_all_text' => 'Voir tout',
					'view_all_url'  => $subscription_anchor,
					'tag_text'      => 'Streaming',
					'category'      => '',
					'product_ids'   => implode( ',', $products['subscriptions'] ),
					'limit'         => 10,
					'orderby'       => 'menu_order',
					'order'         => 'ASC',
					'style'         => 'delicat_abonnement',
					'show_price'    => true,
					'show_stock'    => false,
					'show_cta'      => true,
					'progressive'   => true,
				),
				8
			),
			self::section(
				'home-exchange-carousel',
				'products',
				array(
					'title'         => 'ÉCHANGES',
					'heading_level' => 'h2',
					'title_size_d'  => 0,
					'title_size_t'  => 0,
					'title_size_m'  => 0,
					'title_color'   => '',
					'subtitle'      => 'Vos services financiers essentiels.',
					'view_all_text' => 'Voir tout',
					'view_all_url'  => $exchange_url,
					'tag_text'      => 'Finance',
					'category'      => 'exchange',
					'product_ids'   => implode( ',', $products['exchange'] ),
					'limit'         => 8,
					'orderby'       => 'menu_order',
					'order'         => 'ASC',
					'style'         => 'delicat_jeux',
					'show_price'    => true,
					'show_stock'    => false,
					'show_cta'      => true,
					'progressive'   => true,
				),
				8
			),
			self::section(
				'home-gift-card-carousel',
				'products',
				array(
					'title'         => 'GIFT CARDS',
					'heading_level' => 'h2',
					'title_size_d'  => 0,
					'title_size_t'  => 0,
					'title_size_m'  => 0,
					'title_color'   => '',
					'subtitle'      => 'Cartes cadeaux numériques livrées rapidement.',
					'view_all_text' => 'Voir tout',
					'view_all_url'  => $gift_url,
					'tag_text'      => 'Gift Card',
					'category'      => 'gift-card',
					'product_ids'   => implode( ',', $products['gift_cards'] ),
					'limit'         => 8,
					'orderby'       => 'menu_order',
					'order'         => 'ASC',
					'style'         => 'delicat_abonnement',
					'show_price'    => true,
					'show_stock'    => false,
					'show_cta'      => true,
					'progressive'   => true,
				),
				8
			),
		);

		return Delicat_Builder_V9_Schema::sanitize_layout( $layout );
	}

	public static function backup_once( int $page_id ): void {
		if ( get_post_meta( $page_id, self::BACKUP_META, true ) ) {
			return;
		}
		update_post_meta(
			$page_id,
			self::BACKUP_META,
			array(
				'created_at'      => gmdate( 'c' ),
				'enabled'         => Delicat_Builder_V9_Pages::is_enabled_for_page( $page_id ) ? 1 : 0,
				'layout'          => Delicat_Builder_V9_Pages::get_layout( $page_id ),
				'design_settings' => Delicat_Builder_V9_Design::settings(),
			)
		);
	}

	public static function apply( int $page_id ) {
		if ( ! current_user_can( 'edit_post', $page_id ) || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this page.', 'delicat-builder-v9' ) );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'WooCommerce must be active before applying the homepage starter.', 'delicat-builder-v9' ) );
		}

		$snapshot = self::transaction_snapshot( $page_id );
		self::backup_once( $page_id );

		try {
			$layout = self::build_layout( $page_id );
			if ( empty( $layout ) ) {
				return new WP_Error( 'empty_layout', __( 'Could not generate the homepage starter layout.', 'delicat-builder-v9' ) );
			}

			// Critical safety change: never switch a live page to Builder output
			// before the candidate layout has passed rendering/compile checks.
			update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 0 );

			$smoke = self::smoke_render_layout( $page_id, $layout, 'homepage_starter_smoke' );
			if ( is_wp_error( $smoke ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $smoke;
			}

			$result = Delicat_Builder_V9_Pages::save_layout( $page_id, $layout );
			if ( is_wp_error( $result ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $result;
			}

			self::configure_design_for_page( $page_id, true );
			Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );

			$smoke = self::smoke_render_layout( $page_id, $layout, 'homepage_starter_final_smoke' );
			if ( is_wp_error( $smoke ) ) {
				self::restore_transaction_snapshot( $page_id, $snapshot );
				return $smoke;
			}

			update_post_meta( $page_id, self::MANAGED_META, 1 );
			update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, 1 );
			Delicat_Builder_V9_Cache::purge_page( $page_id );
		} catch ( Throwable $error ) {
			self::restore_transaction_snapshot( $page_id, $snapshot );
			if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
				Delicat_Builder_V9_Renderer::runtime_failure(
					'homepage_starter_transaction',
					$error,
					array( 'page_id' => $page_id )
				);
			}
			return new WP_Error(
				'builder_transaction_failed',
				__( 'The homepage starter was stopped safely because a runtime error was detected. The previous page state was restored.', 'delicat-builder-v9' )
			);
		}

		self::identity_audit(
			'builder_homepage_starter_applied',
			'notice',
			array( 'page_id' => $page_id, 'sections' => count( $layout ) )
		);

		return $layout;
	}

	public static function restore( int $page_id ) {
		if ( ! current_user_can( 'edit_post', $page_id ) || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this page.', 'delicat-builder-v9' ) );
		}
		$backup = get_post_meta( $page_id, self::BACKUP_META, true );
		if ( ! is_array( $backup ) ) {
			return new WP_Error( 'missing_backup', __( 'No homepage starter backup exists for this page.', 'delicat-builder-v9' ) );
		}

		$layout = Delicat_Builder_V9_Schema::sanitize_layout( $backup['layout'] ?? array() );
		Delicat_Builder_V9_Pages::save_layout( $page_id, $layout );
		update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_ENABLED, empty( $backup['enabled'] ) ? 0 : 1 );

		if ( is_array( $backup['design_settings'] ?? null ) ) {
			$design = Delicat_Builder_V9_Design_Admin::sanitize( $backup['design_settings'] );
			update_option( Delicat_Builder_V9_Design::OPTION, $design, false );
			Delicat_Builder_V9_Design::reset_settings_cache();
		}
		delete_post_meta( $page_id, self::MANAGED_META );
		Delicat_Builder_V9_Cache::purge_page( $page_id );
		if ( ! empty( $layout ) ) {
			Delicat_Builder_V9_Compiler::compile_page( $page_id, $layout );
		}

		self::identity_audit(
			'builder_homepage_starter_restored',
			'warning',
			array( 'page_id' => $page_id )
		);
		return true;
	}
	private static function identity_audit( string $event, string $severity = 'info', array $context = array() ): void {
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit( $event, $severity, $context );
		}
	}

}
