<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Renderer {
	private static bool $runtime_failure_recorded = false;
	public static function render_layout( array $layout, bool $already_sanitized = false ): string {
		if ( ! $already_sanitized ) {
			$layout = Delicat_Builder_V9_Schema::sanitize_layout( $layout );
		}
		if ( empty( $layout ) ) {
			return '';
		}

		$components = array_map( 'sanitize_key', array_values( array_unique( array_column( $layout, 'type' ) ) ) );
		$priority_hero_index = null;
		foreach ( $layout as $candidate_index => $candidate ) {
			$visibility = is_array( $candidate['visibility'] ?? null ) ? $candidate['visibility'] : array();
			if ( empty( $visibility['desktop'] ) && empty( $visibility['tablet'] ) && empty( $visibility['mobile'] ) ) {
				continue;
			}
			if (
				'hero' === ( $candidate['type'] ?? '' )
				&& ! empty( $visibility['desktop'] )
				&& ! empty( $visibility['tablet'] )
				&& ! empty( $visibility['mobile'] )
			) {
				$priority_hero_index = (int) $candidate_index;
			}
			break;
		}

		$layout_classes = array( 'delicat-page-layout' );
		$layout_attrs   = array(
			'data-delicat-page-layout' => '1',
			'data-delicat-components'  => implode( ',', $components ),
		);

		if ( class_exists( 'Delicat_Builder_V9_Design' ) && method_exists( 'Delicat_Builder_V9_Design', 'settings' ) ) {
			$design = Delicat_Builder_V9_Design::settings();
			$mode   = in_array( $design['homepage_mode'] ?? 'light', array( 'light', 'dark' ), true ) ? $design['homepage_mode'] : 'light';
			$preset = sanitize_html_class( $design['preset'] ?? 'modern_store' );
			$layout_classes[] = 'delicat-home-mode-' . $mode;
			$layout_classes[] = 'delicat-design-' . $preset;
			$layout_attrs['data-delicat-home-mode'] = $mode;
			$layout_attrs['data-delicat-design']    = $preset;
		}

		$queried_page_id = function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0;
		if (
			$queried_page_id > 0
			&& function_exists( 'delicat_builder_v9_is_managed_page' )
			&& delicat_builder_v9_is_managed_page( $queried_page_id )
		) {
			$layout_classes[] = 'delicat-page-layout--homepage-managed';
			$layout_attrs['data-delicat-homepage-managed'] = '1';
		}

		$attr_html = '';
		foreach ( $layout_attrs as $attr_name => $attr_value ) {
			$attr_html .= ' ' . esc_attr( $attr_name ) . '="' . esc_attr( (string) $attr_value ) . '"';
		}

		$output = '<div class="' . esc_attr( implode( ' ', array_values( array_unique( $layout_classes ) ) ) ) . '"' . $attr_html . '>';
		$rendered_sections = 0;

		foreach ( $layout as $index => $section ) {
			try {
				$chunk = self::render_section(
					$section,
					(int) $index,
					null !== $priority_hero_index && (int) $index === $priority_hero_index
				);
				if ( '' !== $chunk ) {
					$output .= $chunk;
					$rendered_sections++;
				}
			} catch ( Throwable $error ) {
				self::record_runtime_failure(
					'section_render',
					$error,
					array(
						'section_type' => sanitize_key( (string) ( $section['type'] ?? '' ) ),
						'section_id'   => sanitize_key( (string) ( $section['id'] ?? '' ) ),
						'index'        => (int) $index,
					)
				);
				// A single broken component/product integration must never take
				// down the complete homepage.
				continue;
			}
		}

		$output .= '</div>';

		return $rendered_sections > 0 ? $output : '';
	}

private static function record_runtime_failure( string $stage, Throwable $error, array $context = array() ): void {
	if ( self::$runtime_failure_recorded ) {
		return;
	}
	self::$runtime_failure_recorded = true;

	$hash = hash(
		'sha256',
		get_class( $error ) . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine()
	);

	$previous = get_option( 'delicat_builder_v9_last_runtime_failure', array() );
	if (
		is_array( $previous )
		&& hash_equals( (string) ( $previous['hash'] ?? '' ), $hash )
		&& ! empty( $previous['time_unix'] )
		&& time() - absint( $previous['time_unix'] ) < 600
	) {
		return;
	}

	$record = array(
		'time'      => gmdate( 'c' ),
		'time_unix' => time(),
		'stage'     => sanitize_key( $stage ),
		'type'      => sanitize_text_field( get_class( $error ) ),
		'hash'      => $hash,
		'context'   => array_map(
			static function ( $value ) { return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : ''; },
			$context
		),
	);

	// Non-autoloaded operational diagnostic. No raw exception message,
	// stack trace, request payload, cookies or customer data is stored.
	update_option( 'delicat_builder_v9_last_runtime_failure', $record, false );
}

public static function runtime_failure( string $stage, Throwable $error, array $context = array() ): void {
	self::record_runtime_failure( $stage, $error, $context );
}

	public static function layout_needs_carousel( array $layout ): bool {
		foreach ( $layout as $section ) {
			if ( is_array( $section ) && 'products' === ( $section['type'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function render_section( array $section, int $index = 0, bool $priority_hero = false ): string {
		$type    = $section['type'];
		$content = $section['content'];
		$id      = 'delicat-section-' . sanitize_html_class( $section['id'] );
		$classes = array(
			'delicat-section',
			'delicat-section--' . sanitize_html_class( $type ),
		);

		foreach ( array( 'desktop', 'tablet', 'mobile' ) as $device ) {
			if ( empty( $section['visibility'][ $device ] ) ) {
				$classes[] = 'delicat-hide-' . $device;
			}
		}

		$styles = array(
			'--dbv9-space-d:' . absint( $section['spacing']['desktop'] ) . 'px',
			'--dbv9-space-t:' . absint( $section['spacing']['tablet'] ) . 'px',
			'--dbv9-space-m:' . absint( $section['spacing']['mobile'] ) . 'px',
		);

		if ( 'full' !== $section['max_width'] ) {
			$styles[] = '--dbv9-max:' . absint( $section['max_width'] ) . 'px';
		} else {
			$styles[] = '--dbv9-max:100%';
		}

		if ( ! empty( $section['background'] ) ) {
			$styles[] = '--dbv9-bg:' . sanitize_hex_color( $section['background'] );
		}

		$inner = '';
		switch ( $type ) {
			case 'hero':
				$inner = self::hero( $content, $priority_hero );
				break;
			case 'text':
				$inner = self::text( $content );
				break;
			case 'banner':
				$inner = self::banner( $content );
				break;
			case 'products':
				$inner = self::products( $content );
				break;
			case 'category_chips':
				$inner = self::category_chips( $content );
				break;
			case 'how_it_works':
				$inner = self::how_it_works( $content );
				break;
			case 'testimonials':
				$inner = self::testimonials( $content );
				break;
			case 'faq':
				$inner = self::faq( $content );
				break;
			case 'why_delicat':
				$inner = self::why_delicat( $content );
				break;
			case 'favorites':
				$inner = self::favorites( $content );
				break;
			case 'bon_kliyan':
				$inner = self::bon_kliyan( $content );
				break;
			case 'newsletter':
				$inner = self::newsletter( $content );
				break;
			case 'trust_strip':
				$inner = self::trust_strip( $content );
				break;
			case 'spacer':
				$inner = self::spacer( $content );
				break;
		}

		if ( '' === $inner ) {
			return '';
		}

		return sprintf(
			'<section id="%1$s" class="%2$s" style="%3$s" data-delicat-component="%5$s"><div class="delicat-section__inner">%4$s</div></section>',
			esc_attr( $id ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( implode( ';', array_filter( $styles ) ) ),
			$inner,
			esc_attr( $type )
		);
	}

	private static function hero( array $content, bool $priority = false ): string {
		$content['benefits'] = self::upgraded_default(
			$content['benefits'] ?? '',
			array( "lightning|Livraison instantanée\nshield|Paiement sécurisé\nheadset|Support 24/7" ),
			"bolt|Livraison rapide\nshield|Paiement sécurisé\nheadset|Support humain"
		);
		$align = 'center' === $content['align'] ? 'center' : 'left';
		$position = 'left' === ( $content['media_position'] ?? 'right' ) ? 'left' : 'right';
		$min_height = max( 220, min( 760, absint( $content['min_height'] ?? 320 ) ) );
		$hero_style = sanitize_key( (string) ( $content['style'] ?? 'neo_glass_pro' ) );
		if ( ! in_array( $hero_style, array( 'neo_glass_pro', 'minimal', 'solid' ), true ) ) {
			$hero_style = 'neo_glass_pro';
		}

		$background_desktop_id = absint( $content['background_image_id'] ?? 0 );
		$background_mobile_id  = absint( $content['mobile_background_image_id'] ?? 0 );
		$has_background_media  = Delicat_Builder_V9_Media::is_image_attachment( $background_desktop_id )
			|| Delicat_Builder_V9_Media::is_image_attachment( $background_mobile_id );

		$image = Delicat_Builder_V9_Media::picture(
			absint( $content['image_id'] ),
			absint( $content['mobile_image_id'] ?? 0 ),
			'large',
			'delicat-builder-hero__image',
			$priority && ! $has_background_media
		);

		$background = Delicat_Builder_V9_Media::picture(
			$background_desktop_id,
			$background_mobile_id,
			'large',
			'delicat-builder-hero__background-image',
			$priority && $has_background_media,
			'(max-width:1200px) 100vw, 1200px'
		);
		$background_position = sanitize_key( (string) ( $content['background_position'] ?? 'center' ) );
		if ( ! in_array( $background_position, array( 'center', 'top', 'bottom', 'left', 'right' ), true ) ) {
			$background_position = 'center';
		}
		$background_overlay = max( 0, min( 85, absint( $content['background_overlay'] ?? 46 ) ) );

		$benefits = array();
		$benefit_lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['benefits'] ?? '' ) );
		foreach ( array_slice( (array) $benefit_lines, 0, 3 ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( count( $parts ) < 2 || '' === $parts[1] ) {
				continue;
			}
			$benefits[] = array(
				'icon'  => sanitize_key( $parts[0] ),
				'label' => sanitize_text_field( $parts[1] ),
			);
		}

		$title_markup = '';
		$title = (string) ( $content['title'] ?? '' );
		$highlight = trim( (string) ( $content['highlight_text'] ?? '' ) );
		if ( '' !== $title ) {
			if ( '' !== $highlight ) {
				$pos = strpos( $title, $highlight );
				if ( false !== $pos ) {
					$before = substr( $title, 0, $pos );
					$after = substr( $title, $pos + strlen( $highlight ) );
					$title_markup = esc_html( $before )
						. '<span class="delicat-builder-hero__highlight">' . esc_html( $highlight ) . '</span>'
						. esc_html( $after );
				}
			}
			if ( '' === $title_markup ) {
				$title_markup = esc_html( $title );
			}
		}

		$copy  = '<div class="delicat-builder-hero__copy delicat-align-' . esc_attr( $align ) . '">';
		if ( '' !== $content['eyebrow'] ) {
			$copy .= '<div class="delicat-builder-hero__eyebrow"><span class="delicat-builder-hero__eyebrow-dot" aria-hidden="true"></span>' . esc_html( $content['eyebrow'] ) . '</div>';
		}
		if ( '' !== $title_markup ) {
			/* RC80: the hero is the page's h1, so it claims the slot before any
			 * rail can ask for one. */
			$hero_tag = ( class_exists( 'Delicat_Builder_V9_Carousel', false ) && is_callable( array( 'Delicat_Builder_V9_Carousel', 'claim_h1' ) ) && ! Delicat_Builder_V9_Carousel::claim_h1() )
				? 'h2'
				: 'h1';
			$copy .= '<' . $hero_tag . ' class="delicat-builder-hero__title">' . $title_markup . '</' . $hero_tag . '>';
		}
		if ( '' !== $content['text'] ) {
			$copy .= '<div class="delicat-builder-hero__text">' . wp_kses_post( wpautop( $content['text'] ) ) . '</div>';
		}
		if ( '' !== $content['button_text'] && '' !== $content['button_url'] ) {
			$copy .= '<a class="delicat-builder-button delicat-builder-hero__cta" href="' . esc_url( $content['button_url'] ) . '" data-delicat-prefetch><span>' . esc_html( $content['button_text'] ) . '</span><span class="delicat-builder-hero__cta-arrow" aria-hidden="true">→</span></a>';
		}
		if ( ! empty( $benefits ) ) {
			$copy .= '<div class="delicat-builder-hero__benefits" role="list" aria-label="' . esc_attr__( 'Store benefits', 'delicat-builder-v9' ) . '">';
			foreach ( $benefits as $benefit ) {
				$copy .= '<span class="delicat-builder-hero__benefit" role="listitem">' . self::icon_markup( $benefit['icon'] ) . '<span>' . esc_html( $benefit['label'] ) . '</span></span>';
			}
			$copy .= '</div>';
		}

		$search_enabled = ! empty( $content['search_enabled'] );
		if ( $search_enabled ) {
			static $hero_search_counter = 0;
			$hero_search_counter++;
			$search_input_id = 'delicat-hero-product-search-' . $hero_search_counter;
			$index_limit = max( 10, min( 120, absint( $content['search_index_limit'] ?? 60 ) ) );
			$result_limit = max( 3, min( 8, absint( $content['search_results_limit'] ?? 5 ) ) );
			$min_chars = max( 1, min( 4, absint( $content['search_min_chars'] ?? 2 ) ) );
			$placeholder = (string) ( $content['search_placeholder'] ?? __( 'Rechercher un jeu, une carte ou un service…', 'delicat-builder-v9' ) );
			/*
			 * pro.17: the index class is listed only among the Builder admin modules,
			 * so on the storefront this test always failed and the hero shipped an
			 * empty suggestion list — the type-ahead could never match anything.
			 * Load it here, where it is used, and only when a hero actually renders a
			 * search field. index() is transient-cached for 15 minutes against the
			 * Builder cache version, so this is one query per cache generation.
			 */
			if ( ! class_exists( 'Delicat_Builder_V9_Hero_Search' ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
				delicat_builder_v9_safe_require( 'includes/class-delicat-builder-hero-search.php' );
			}
			$index = class_exists( 'Delicat_Builder_V9_Hero_Search' ) ? Delicat_Builder_V9_Hero_Search::index( $index_limit ) : array();
			$search_data = wp_json_encode( $index, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			$copy .= '<div class="delicat-builder-hero-search" data-delicat-hero-search data-min-chars="' . esc_attr( (string) $min_chars ) . '" data-result-limit="' . esc_attr( (string) $result_limit ) . '">';
			$copy .= '<form class="delicat-builder-hero-search__form" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">';
			$copy .= '<span class="delicat-builder-hero-search__icon" aria-hidden="true">' . self::icon_markup( 'search' ) . '</span>';
			$copy .= '<label class="screen-reader-text" for="' . esc_attr( $search_input_id ) . '">' . esc_html__( 'Rechercher un produit', 'delicat-builder-v9' ) . '</label>';
			$copy .= '<input id="' . esc_attr( $search_input_id ) . '" class="delicat-builder-hero-search__input" type="search" name="s" autocomplete="off" enterkeyhint="search" spellcheck="false" placeholder="' . esc_attr( $placeholder ) . '" data-delicat-hero-search-input>';
			$copy .= '<input type="hidden" name="post_type" value="product">';
			$copy .= '<button class="delicat-builder-hero-search__submit" type="submit" aria-label="' . esc_attr__( 'Rechercher', 'delicat-builder-v9' ) . '"><span aria-hidden="true">→</span></button>';
			$copy .= '</form>';
			$copy .= '<div class="delicat-builder-hero-search__results" data-delicat-hero-search-results role="listbox" hidden></div>';
			$copy .= '<script type="application/json" class="delicat-builder-hero-search__data">' . ( $search_data ?: '[]' ) . '</script>';
			$copy .= '</div>';
		}
		$copy .= '</div>';

		$media = $image ? '<div class="delicat-builder-hero__media">' . $image . '</div>' : '';
		$body  = ( 'left' === $position && $media ) ? $media . $copy : $copy . $media;
		$background_markup = $background
			? '<div class="delicat-builder-hero__background" aria-hidden="true">' . $background . '<span class="delicat-builder-hero__background-overlay"></span></div>'
			: '';

		/*
		 * RC61: floating icon layer. Static markup — identical for every
		 * visitor — animated by CSS keyframes only. Each tile carries its own
		 * tone as a custom property; position, duration and delay come from
		 * the stylesheet slot (nth-child), so the HTML never changes between
		 * requests and the LiteSpeed copy stays valid.
		 */
		$float_markup = '';
		$float_enabled = ! isset( $content['float_enabled'] ) || ! empty( $content['float_enabled'] );
		if ( $float_enabled ) {
			$float_lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['float_icons'] ?? '' ) );
			$float_items = array();
			foreach ( array_slice( (array) $float_lines, 0, 10 ) as $line ) {
				$parts = array_map( 'trim', explode( '|', $line, 2 ) );
				if ( '' === $parts[0] ) {
					continue;
				}
				$float_items[] = array(
					'icon' => sanitize_text_field( $parts[0] ),
					'tone' => sanitize_hex_color( (string) ( $parts[1] ?? '' ) ) ?: '',
				);
			}
			if ( ! empty( $float_items ) ) {
				$float_tones = array( '#6a5cff', '#e0407f', '#22a06b', '#d9860c', '#8b5cf6', '#1f7fd6', '#ff4d91', '#f0b429' );
				$float_markup = '<div class="delicat-builder-hero__float" aria-hidden="true">';
				foreach ( $float_items as $float_index => $float_item ) {
					$tone = $float_item['tone'] ?: $float_tones[ $float_index % count( $float_tones ) ];
					$float_markup .= '<span class="delicat-builder-hero__float-item" style="' . esc_attr( '--dbv9-float-tone:' . $tone ) . '">' . self::icon_markup( $float_item['icon'] ) . '</span>';
				}
				$float_markup .= '</div>';
			}
		}
		$float_size    = max( 28, min( 96, absint( $content['float_size'] ?? 52 ) ) );
		$float_size_m  = max( 28, min( 96, absint( $content['float_size_m'] ?? 58 ) ) );
		$float_opacity = max( 10, min( 100, absint( $content['float_opacity'] ?? 62 ) ) );
		$float_speed   = max( 3, min( 16, absint( $content['float_speed'] ?? 7 ) ) );
		$pattern_enabled = ! isset( $content['pattern_enabled'] ) || ! empty( $content['pattern_enabled'] );
		/* RC64: mesh gradient background — four tunable glows over three
		 * gradient stops; "glass" keeps the pale RC60 look. */
		$mesh = 'glass' !== ( $content['background_style'] ?? 'mesh' );
		$mesh_vars = ';--dbv9-hero-bg-start:' . self::color_or( $content['bg_start'] ?? '', '#eceeff' )
			. ';--dbv9-hero-bg-mid:' . self::color_or( $content['bg_mid'] ?? '', '#f6ecff' )
			. ';--dbv9-hero-bg-end:' . self::color_or( $content['bg_end'] ?? '', '#ffe9f0' )
			. ';--dbv9-hero-glow-1:' . self::color_or( $content['glow_1'] ?? '', '#7d6cff' )
			. ';--dbv9-hero-glow-2:' . self::color_or( $content['glow_2'] ?? '', '#ff4d91' )
			. ';--dbv9-hero-glow-3:' . self::color_or( $content['glow_3'] ?? '', '#28c8ff' )
			. ';--dbv9-hero-glow-4:' . self::color_or( $content['glow_4'] ?? '', '#ffb547' )
			. ';--dbv9-hero-glow-a:' . ( max( 0, min( 100, absint( $content['glow_strength'] ?? 36 ) ) ) / 100 );

		$classes = 'delicat-builder-hero delicat-builder-hero--media-' . esc_attr( $position ) . ' delicat-builder-hero--style-' . esc_attr( $hero_style );
		$classes .= $image ? ' has-media' : '';
		$classes .= $background ? ' has-background' : '';
		$classes .= $search_enabled ? ' has-search' : '';
		$classes .= '' !== $float_markup ? ' has-float' : '';
		$classes .= $pattern_enabled ? ' has-pattern' : '';
		$classes .= $mesh ? ' is-mesh' : '';
		$style = '--dbv9-hero-min:' . $min_height . 'px;--dbv9-hero-bg-overlay:' . ( $background_overlay / 100 ) . ';--dbv9-hero-bg-position:' . esc_attr( $background_position )
			. ';--dbv9-hero-float-size:' . $float_size . 'px;--dbv9-hero-float-size-m:' . $float_size_m . 'px;--dbv9-hero-float-opacity:' . ( $float_opacity / 100 ) . ';--dbv9-hero-float-speed:' . $float_speed . 's'
			. ( $mesh ? $mesh_vars : '' );

		return '<div class="' . $classes . '" style="' . esc_attr( $style ) . '">' . $background_markup . $float_markup . $body . '</div>';
	}

	private static function text( array $content ): string {
		$align = 'center' === $content['align'] ? 'center' : 'left';
		$out   = '<div class="delicat-builder-text delicat-align-' . esc_attr( $align ) . '">';
		if ( '' !== $content['title'] ) {
			$out .= '<h2 class="delicat-builder-text__title">' . apply_filters( 'delicat_builder_v9_section_text', esc_html( $content['title'] ) ) . '</h2>';
		}
		if ( '' !== $content['text'] ) {
			$out .= '<div class="delicat-builder-text__copy">' . wp_kses_post( wpautop( $content['text'] ) ) . '</div>';
		}
		$out .= '</div>';
		return $out;
	}

	private static function banner( array $content ): string {
		$image = Delicat_Builder_V9_Media::image( absint( $content['image_id'] ), 'large', 'delicat-builder-banner__image', false, '(max-width:960px) 100vw, 40vw' );
		$out   = '<div class="delicat-builder-banner' . ( $image ? ' has-media' : '' ) . '">';
		if ( $image ) {
			$out .= '<div class="delicat-builder-banner__media">' . $image . '</div>';
		}
		$out .= '<div class="delicat-builder-banner__copy">';
		if ( '' !== $content['title'] ) {
			$out .= '<h2>' . apply_filters( 'delicat_builder_v9_section_text', esc_html( $content['title'] ) ) . '</h2>';
		}
		if ( '' !== $content['text'] ) {
			$out .= '<div>' . wp_kses_post( wpautop( $content['text'] ) ) . '</div>';
		}
		if ( '' !== $content['button_text'] && '' !== $content['button_url'] ) {
			$out .= '<a class="delicat-builder-button" href="' . esc_url( $content['button_url'] ) . '" data-delicat-prefetch>' . esc_html( $content['button_text'] ) . '</a>';
		}
		$out .= '</div></div>';
		return $out;
	}

	private static function products( array $content ): string {
		$atts = array(
			'eyebrow'       => $content['eyebrow'] ?? '',
			'title'         => $content['title'],
			'display_variant' => $content['display_variant'] ?? 'standard',
			'heading_level' => $content['heading_level'] ?? 'h2',
			'title_size_d'  => $content['title_size_d'] ?? 0,
			'title_size_t'  => $content['title_size_t'] ?? 0,
			'title_size_m'  => $content['title_size_m'] ?? 0,
			'title_color'   => $content['title_color'] ?? '',
			'subtitle'      => $content['subtitle'] ?? '',
			'subtitle_color'=> $content['subtitle_color'] ?? '',
			'gap_mode'      => $content['gap_mode'] ?? 'global',
			'card_gap_d'    => $content['card_gap_d'] ?? 0,
			'card_gap_t'    => $content['card_gap_t'] ?? 0,
			'card_gap_m'    => $content['card_gap_m'] ?? 0,
			'section_gap_d' => $content['section_gap_d'] ?? 38,
			'section_gap_t' => $content['section_gap_t'] ?? 34,
			'section_gap_m' => $content['section_gap_m'] ?? 30,
			'product_name_d'=> $content['product_name_d'] ?? 0,
			'product_name_t'=> $content['product_name_t'] ?? 0,
			'product_name_m'=> $content['product_name_m'] ?? 0,
			'media_title_d' => $content['media_title_d'] ?? 30,
			'media_title_t' => $content['media_title_t'] ?? 25,
			'media_title_m' => $content['media_title_m'] ?? 20,
			'badge_mode'    => $content['badge_mode'] ?? 'auto',
			'badge_bg'      => $content['badge_bg'] ?? '',
			'badge_text'    => $content['badge_text'] ?? '',
			'status_badge_mode' => $content['status_badge_mode'] ?? 'auto',
			'status_new_days'   => $content['status_new_days'] ?? 30,
			'status_sales_min'  => $content['status_sales_min'] ?? 10,
			'pagination_mode'   => $content['pagination_mode'] ?? 'pages',
			'heart_mode'    => $content['heart_mode'] ?? 'auto',
			'heart_bg'      => $content['heart_bg'] ?? '',
			'heart_color'   => $content['heart_color'] ?? '',
			'heart_icon'    => $content['heart_icon'] ?? 'inherit',
			'view_all_text' => $content['view_all_text'] ?? '',
			'view_all_url'  => $content['view_all_url'] ?? '',
			'tag_text'      => $content['tag_text'] ?? '',
			'category'    => $content['category'],
			'product_ids' => $content['product_ids'] ?? '',
			'limit'       => $content['limit'],
			'orderby'     => $content['orderby'],
			'order'       => $content['order'],
			'stock'       => 'instock',
			'style'       => $content['style'] ?? 'premium',
			'show_price'  => ! empty( $content['show_price'] ) ? 'yes' : 'no',
			'show_stock'  => ! empty( $content['show_stock'] ) ? 'yes' : 'no',
			'show_cta'    => ! isset( $content['show_cta'] ) || ! empty( $content['show_cta'] ) ? 'yes' : 'no',
			'progressive' => ! empty( $content['progressive'] ) ? 'yes' : 'no',
		);
		try {
			return Delicat_Builder_V9_Carousel::shortcode( $atts );
		} catch ( Throwable $error ) {
			self::record_runtime_failure(
				'product_carousel',
				$error,
				array(
					'category' => sanitize_key( (string) ( $content['category'] ?? '' ) ),
					'limit'    => absint( $content['limit'] ?? 0 ),
				)
			);
			return '';
		}
	}




private static function icon_markup( string $token ): string {
	$key = sanitize_key( $token );

	$icons = array(
		'cursor' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M5 3l12 8-5 2 3 5-2 1-3-5-4 4z"/></svg>',
		'chat' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M5 5h14v10H9l-4 4z"/></svg>',
		'rocket' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M14 4c3 0 5 0 6 0 0 4-1 8-5 11l-4-4c1-3 2-5 3-7zm-4 8l3 3-4 4-4 1 1-4zm7-5a2 2 0 1 0 0 .01z"/></svg>',
		'lightning' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M13 2L5 13h6l-1 9 8-12h-6z"/></svg>',
		'shield' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5zm-3 9l2 2 4-4 2 2-6 6-4-4z"/></svg>',
		'globe' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 4 6 4 9s-1 6-4 9c-3-3-4-6-4-9s1-6 4-9z"/></svg>',
		'headset' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M4 13v-2a8 8 0 0 1 16 0v6h-4v-6h4M4 11v6h4v-6zm12 7h-4"/></svg>',
		'verified' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M12 2l3 2 4 1 1 4 2 3-2 3-1 4-4 1-3 2-3-2-4-1-1-4-2-3 2-3 1-4 4-1zm-4 10l3 3 5-6 2 2-7 8-5-5z"/></svg>',
		'tag' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 4h8l10 10-7 7L4 11zm5 3a2 2 0 1 0 0 .01z"/></svg>',
		'phone' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/></svg>',
		'wallet' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 6h16v13H3zM5 3h12v3H5zm10 8h6v4h-6z"/></svg>',
		'card' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 9h20"/></svg>',
		'mail' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>',
		'lock' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
		'store' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 9.5 4.6 4h14.8L21 9.5"/><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12v8h14v-8"/><path d="M10 20v-5h4v5"/></svg>',
		'bolt' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M13 2 4.5 13.2h6.2L10 22l9-12h-6.3L13 2Z"/></svg>',
		'heart' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M12 20.5s-7.5-4.6-9.3-9.5C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/></svg>',
		'sparkles' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><path d="m7.8 7.8 2 2M14.2 14.2l2 2M7.8 16.2l2-2M14.2 9.8l2-2"/><circle cx="12" cy="12" r="2.2"/></svg>',
		'gift' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 9h18v3H3zM5 12v9h14v-9"/><path d="M12 9v12"/><path d="M12 9c-1.5-3.5-5-4.5-5-2 0 2 5 2 5 2Zm0 0c1.5-3.5 5-4.5 5-2 0 2-5 2-5 2Z"/></svg>',
		'gamepad' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M6.5 7h11a4.5 4.5 0 0 1 4.4 5.4l-.9 4.4a2.6 2.6 0 0 1-4.7 1L15 16H9l-1.3 1.8a2.6 2.6 0 0 1-4.7-1l-.9-4.4A4.5 4.5 0 0 1 6.5 7Z"/><path d="M8 10v3M6.5 11.5h3M15.5 11h.01M17.5 12.5h.01"/></svg>',
		'tv' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8M12 17v4"/><path d="m10 8.5 5 2.5-5 2.5z"/></svg>',
		'coins' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><ellipse cx="9" cy="7" rx="6" ry="2.6"/><path d="M3 7v5c0 1.4 2.7 2.6 6 2.6s6-1.2 6-2.6V7"/><path d="M3 12v5c0 1.4 2.7 2.6 6 2.6s6-1.2 6-2.6v-5"/><path d="M15 9.4c3.4.2 6 1.4 6 2.6v5c0 1.4-2.7 2.6-6 2.6"/></svg>',
		'search' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
		'crown' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 8.5 7.2 12l4.8-6.5 4.8 6.5L21 8.5 19 18H5z"/><path d="M5.6 20.5h12.8"/></svg>',
		'star' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="m12 3.2 2.7 5.6 6.1.8-4.5 4.3 1.1 6.1L12 17.1 6.6 20l1.1-6.1L3.2 9.6l6.1-.8z"/></svg>',
		'trophy' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"/><path d="M12 14v3M8.5 20.5h7M10 17h4v3.5h-4z"/></svg>',
		'ticket' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2.5a1.5 1.5 0 0 0 0 3V16a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2.5a1.5 1.5 0 0 0 0-3z"/><path d="M9 8v8"/></svg>',
		'percent' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="m6 18 12-12"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg>',
		'diamond' => '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false" style="display:block;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round"><path d="M7 4h10l4 5.5L12 20 3 9.5z"/><path d="M3 9.5h18M9 4l3 5.5L15 4M9.5 9.5 12 20l2.5-10.5"/></svg>',
	);

	if ( isset( $icons[ $key ] ) ) {
		return '<span class="delicat-inline-icon delicat-inline-icon--' . esc_attr( $key ) . '" style="display:inline-grid;width:1em;height:1em;max-width:1em;max-height:1em;min-width:0;min-height:0;line-height:1">' . $icons[ $key ] . '</span>';
	}

	return '<span class="delicat-inline-icon delicat-inline-icon--text" aria-hidden="true" style="display:inline-grid;width:1em;height:1em;max-width:1em;max-height:1em;min-width:0;min-height:0;line-height:1">' . esc_html( $token ) . '</span>';
}


/**
 * RC58: the "ultra" reference look prints titles in sentence case, but layouts
 * saved under the previous look carry their titles as literal capitals
 * ("CHOISIS TON PACK"). A string that is entirely upper case is folded to
 * "Choisis ton pack"; anything with mixed case is the merchant's own casing
 * and is left alone.
 */
private static function sentence_case( string $text ): string {
	$text = trim( $text );
	if ( '' === $text ) {
		return $text;
	}
	$upper = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	if ( $upper !== $text ) {
		return $text;
	}
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	$first = function_exists( 'mb_substr' ) ? mb_substr( $lower, 0, 1, 'UTF-8' ) : substr( $lower, 0, 1 );
	$rest  = function_exists( 'mb_substr' ) ? mb_substr( $lower, 1, null, 'UTF-8' ) : substr( $lower, 1 );
	return ( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first, 'UTF-8' ) : strtoupper( $first ) ) . $rest;
}

/**
 * RC58: when a stored value still equals the previous shipped default it was
 * never customised — hand back the new reference default instead. Anything
 * the merchant typed is returned untouched.
 */
private static function upgraded_default( $value, array $previous_defaults, string $new_default ): string {
	$value = (string) $value;
	$norm = preg_replace( '/\s+/u', ' ', trim( $value ) );
	foreach ( $previous_defaults as $previous ) {
		if ( $norm === preg_replace( '/\s+/u', ' ', trim( (string) $previous ) ) ) {
			return $new_default;
		}
	}
	return $value;
}

/** RC59: the previous look shipped 38/36/28 px titles; the reference look measures 34/30/26. Untouched values follow. */
private static function reference_title_sizes( array $content ): array {
	if ( 38 === absint( $content['title_size_d'] ?? 38 ) && 36 === absint( $content['title_size_t'] ?? 36 ) && 28 === absint( $content['title_size_m'] ?? 28 ) ) {
		$content['title_size_d'] = 34;
		$content['title_size_t'] = 30;
		$content['title_size_m'] = 26;
	}
	return $content;
}

private static function eyebrow_markup( string $eyebrow, string $class, string $color ): string {
	if ( '' === trim( $eyebrow ) ) {
		return '';
	}
	$style = $color ? ' style="--dbv9-eyebrow:' . esc_attr( $color ) . '"' : '';
	return '<span class="' . esc_attr( $class ) . '__eyebrow"' . $style . '>' . esc_html( $eyebrow ) . '</span>';
}

private static function info_heading( array $content, string $class ): string {
	$styles = array(
		'--dbv9-info-title-d:' . absint( $content['title_size_d'] ?? 38 ) . 'px',
		'--dbv9-info-title-t:' . absint( $content['title_size_t'] ?? 36 ) . 'px',
		'--dbv9-info-title-m:' . absint( $content['title_size_m'] ?? 28 ) . 'px',
	);
	if ( ! empty( $content['title_color'] ) ) {
		$styles[] = '--dbv9-info-title:' . sanitize_hex_color( $content['title_color'] );
	}
	if ( ! empty( $content['subtitle_color'] ) ) {
		$styles[] = '--dbv9-info-subtitle:' . sanitize_hex_color( $content['subtitle_color'] );
	}

	$out = '<header class="' . esc_attr( $class ) . '__head" style="' . esc_attr( implode( ';', array_filter( $styles ) ) ) . '">';
	if ( ! empty( $content['title'] ) ) {
		$out .= '<h2 class="' . esc_attr( $class ) . '__title">' . apply_filters( 'delicat_builder_v9_section_text', esc_html( $content['title'] ) ) . '</h2>';
	}
	if ( ! empty( $content['subtitle'] ) ) {
		$out .= '<p class="' . esc_attr( $class ) . '__subtitle">' . apply_filters( 'delicat_builder_v9_section_text', esc_html( $content['subtitle'] ) ) . '</p>';
	}
	$out .= '</header>';
	return $out;
}

private static function how_it_works( array $content ): string {
	/* RC58: the reference look (Ultra Fast "Comment ça marche") is the default;
	 * "neon" keeps the previous dark cards for layouts that choose it. Layouts
	 * saved before the field existed render as "ultra" — and any text that
	 * still equals the previous shipped default is upgraded to the reference
	 * copy, so a never-customised block matches the reference out of the box. */
	$style = in_array( (string) ( $content['style'] ?? 'ultra' ), array( 'ultra', 'neon' ), true ) ? (string) ( $content['style'] ?? 'ultra' ) : 'ultra';
	if ( 'ultra' === $style ) {
		$content['title'] = self::upgraded_default( $content['title'] ?? '', array( 'Comment ça marche ⚡', 'Comment ça marche ⚡️' ), 'Comment ça marche' );
		if ( preg_match( '/^Comment ça marche\s*⚡(?:️)?\s*$/iu', (string) $content['title'] ) ) {
			$content['title'] = 'Comment ça marche';
		}
		$content['subtitle'] = self::upgraded_default( $content['subtitle'] ?? '', array( 'Trois étapes, moins d’une minute d’attente.', 'Trois étapes. Moins d’une minute d’attente.' ), 'Trois étapes. Un délai clair selon le produit.' );
		$content['steps'] = self::upgraded_default(
			$content['steps'] ?? '',
			array( "cursor|CHOISIS TON PACK|Sélectionne ton jeu, ton abonnement ou ta carte cadeau et le montant souhaité.\nchat|CONFIRME TA COMMANDE|Envoie ton ID de joueur ou ton email, puis paie via MonCash, carte ou virement.\nrocket|REÇOIS EN 1 MINUTE|Ta recharge est créditée automatiquement, avec confirmation immédiate sur WhatsApp.", "cursor|Choisis ton pack|Sélectionne ton jeu, ton abonnement ou ta carte cadeau et le montant souhaité.\nchat|Confirme ta commande|Envoie ton ID de joueur ou ton email, puis paie via MonCash, NATCASH.\nrocket|EN MOINS DE 60s|Ta recharge est créditée automatiquement, avec confirmation immédiate sur votre e-mail et WhatsApp." ),
			"store|Choisis ton pack|Sélectionne ton jeu, ton abonnement ou ta carte cadeau et le montant souhaité.\ncard|Confirme ta commande|Ajoute ton identifiant puis paie avec ton portefeuille, MonCash ou Natcash.\nbolt|Suis la livraison|Le délai dépend du produit et l’état de ta commande reste visible dans ton espace client."
		);
		if ( ! array_key_exists( 'eyebrow', $content ) ) {
			$content['eyebrow'] = 'Simple comme bonjour';
		}
		$content['title'] = self::sentence_case( (string) ( $content['title'] ?? '' ) );
		$content = self::reference_title_sizes( $content );
	}
	$lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['steps'] ?? '' ) );
	$steps = array();
	foreach ( array_slice( (array) $lines, 0, 6 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 3 ) );
		if ( count( $parts ) < 3 || '' === $parts[1] ) {
			continue;
		}
		$legacy_delivery_step = preg_match( '/(?:EN\s+MOINS\s+DE\s*60\s*S|RE[ÇC]OIS\s+EN\s+1\s+MINUTE)/iu', (string) $parts[1] )
			|| preg_match( '/confirmation\s+immédiate|créditée\s+automatiquement/iu', (string) $parts[2] );
		if ( $legacy_delivery_step ) {
			$parts[0] = 'bolt';
			$parts[1] = 'Suis la livraison';
			$parts[2] = 'Le délai dépend du produit et l’état de ta commande reste visible dans ton espace client.';
		}
		$steps[] = array(
			'icon'  => sanitize_text_field( $parts[0] ),
			'title' => 'ultra' === $style ? self::sentence_case( sanitize_text_field( $parts[1] ) ) : sanitize_text_field( $parts[1] ),
			'text'  => sanitize_text_field( $parts[2] ),
		);
	}
	if ( empty( $steps ) ) {
		return '';
	}

	if ( 'ultra' === $style ) {
		$vars = array(
			'--dbv9-process-bg:' . sanitize_hex_color( $content['ultra_bg'] ?? '#f4f3ff' ),
			'--dbv9-process-border:' . sanitize_hex_color( $content['ultra_border'] ?? '#e6e4fb' ),
			'--dbv9-process-title:' . sanitize_hex_color( $content['ultra_title'] ?? '#111827' ),
			'--dbv9-process-text:' . sanitize_hex_color( $content['ultra_text'] ?? '#6b7280' ),
			'--dbv9-process-icon-bg:' . sanitize_hex_color( $content['ultra_icon_bg'] ?? '#ffffff' ),
			'--dbv9-process-icon:' . sanitize_hex_color( $content['ultra_icon'] ?? '#6a5cff' ),
			'--dbv9-process-number:' . sanitize_hex_color( $content['ultra_number'] ?? '#dcdfe8' ),
			'--dbv9-process-gap:' . absint( $content['card_gap'] ?? 18 ) . 'px',
			'--dbv9-process-radius:' . max( 8, min( 48, absint( $content['ultra_radius'] ?? 14 ) ) ) . 'px',
			'--dbv9-eyebrow:' . sanitize_hex_color( $content['eyebrow_color'] ?? '#6a5cff' ),
		);
		$out = '<div class="delicat-process delicat-process--ultra" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
		$out .= '<header class="delicat-process__head" style="' . esc_attr( '--dbv9-info-title-d:' . absint( $content['title_size_d'] ?? 38 ) . 'px;--dbv9-info-title-t:' . absint( $content['title_size_t'] ?? 36 ) . 'px;--dbv9-info-title-m:' . absint( $content['title_size_m'] ?? 28 ) . 'px' . ( ! empty( $content['title_color'] ) ? ';--dbv9-info-title:' . sanitize_hex_color( $content['title_color'] ) : '' ) . ( ! empty( $content['subtitle_color'] ) ? ';--dbv9-info-subtitle:' . sanitize_hex_color( $content['subtitle_color'] ) : '' ) ) . '">';
		$out .= self::eyebrow_markup( (string) ( $content['eyebrow'] ?? '' ), 'delicat-process', '' );
		if ( ! empty( $content['title'] ) ) {
			$out .= '<h2 class="delicat-process__title">' . esc_html( $content['title'] ) . '</h2>';
		}
		if ( ! empty( $content['subtitle'] ) ) {
			$out .= '<p class="delicat-process__subtitle">' . esc_html( $content['subtitle'] ) . '</p>';
		}
		$out .= '</header><div class="delicat-process__grid">';
		foreach ( $steps as $index => $step ) {
			$out .= '<article class="delicat-process__card">';
			$out .= '<span class="delicat-process__number" aria-hidden="true">' . esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ) . '</span>';
			$out .= '<div class="delicat-process__icon" aria-hidden="true">' . self::icon_markup( $step['icon'] ) . '</div>';
			$out .= '<h3>' . esc_html( $step['title'] ) . '</h3>';
			$out .= '<p>' . esc_html( $step['text'] ) . '</p>';
			$out .= '</article>';
		}
		$out .= '</div></div>';
		return $out;
	}

	$vars = array(
		'--dbv9-process-bg:' . sanitize_hex_color( $content['card_bg'] ?? '#0a0a23' ),
		'--dbv9-process-border:' . sanitize_hex_color( $content['card_border'] ?? '#25264d' ),
		'--dbv9-process-title:' . sanitize_hex_color( $content['card_title'] ?? '#f8f8ff' ),
		'--dbv9-process-text:' . sanitize_hex_color( $content['card_text'] ?? '#989bad' ),
		'--dbv9-process-a1:' . sanitize_hex_color( $content['accent_1'] ?? '#5964ff' ),
		'--dbv9-process-a2:' . sanitize_hex_color( $content['accent_2'] ?? '#ee5abd' ),
		'--dbv9-process-a3:' . sanitize_hex_color( $content['accent_3'] ?? '#56d8f1' ),
		'--dbv9-process-gap:' . absint( $content['card_gap'] ?? 18 ) . 'px',
		'--dbv9-process-radius:' . absint( $content['card_radius'] ?? 30 ) . 'px',
		'--dbv9-process-num-opacity:' . ( max( 0, min( 30, absint( $content['number_opacity'] ?? 7 ) ) ) / 100 ),
	);

	$out = '<div class="delicat-process" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= self::info_heading( $content, 'delicat-process' );
	$out .= '<div class="delicat-process__grid">';
	foreach ( $steps as $index => $step ) {
		$tone = ( $index % 3 ) + 1;
		$out .= '<article class="delicat-process__card delicat-process__card--' . esc_attr( (string) $tone ) . '">';
		$out .= '<span class="delicat-process__number" aria-hidden="true">' . esc_html( (string) ( $index + 1 ) ) . '</span>';
		$out .= '<div class="delicat-process__icon" aria-hidden="true">' . self::icon_markup( $step['icon'] ) . '</div>';
		$out .= '<h3>' . esc_html( $step['title'] ) . '</h3>';
		$out .= '<p>' . esc_html( $step['text'] ) . '</p>';
		$out .= '</article>';
	}
	$out .= '</div></div>';
	return $out;
}

private static function testimonials( array $content ): string {
	$lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['items'] ?? '' ) );
	$items = array();
	foreach ( array_slice( (array) $lines, 0, 10 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 5 ) );
		if ( count( $parts ) < 3 || '' === $parts[0] || '' === $parts[1] ) {
			continue;
		}
		/* RC63: optional 5th field — a WooCommerce product id or a URL — links
		 * the seeded testimonial to the product it talks about. */
		$product_name = '';
		$product_url  = '';
		$link = trim( (string) ( $parts[4] ?? '' ) );
		if ( '' !== $link ) {
			if ( ctype_digit( $link ) ) {
				$product_post = get_post( absint( $link ) );
				if ( $product_post instanceof WP_Post && 'product' === $product_post->post_type && 'publish' === $product_post->post_status ) {
					$product_name = (string) $product_post->post_title;
					$product_url  = (string) get_permalink( $product_post );
				}
			} else {
				$product_url = esc_url_raw( $link );
			}
		}
		$items[] = array(
			'quote'   => sanitize_text_field( $parts[0] ),
			'name'    => sanitize_text_field( $parts[1] ),
			'place'   => sanitize_text_field( $parts[2] ),
			'rating'  => max( 1, min( 5, absint( $parts[3] ?? 5 ) ) ),
			'product' => sanitize_text_field( $product_name ),
			'url'     => $product_url,
		);
	}
	$link_products = ! isset( $content['link_products'] ) || ! empty( $content['link_products'] );
	/* RC51.28/RC51.30: real reviews (popup submissions + approved WooCommerce
	   product reviews) lead the rail, seeded items follow. */
	$approved_count = 0;
	if ( class_exists( 'Delicat_Builder_V9_Reviews', false ) && is_callable( array( 'Delicat_Builder_V9_Reviews', 'merge_items' ) ) ) {
		$items          = Delicat_Builder_V9_Reviews::merge_items( $items );
		$approved_count = is_callable( array( 'Delicat_Builder_V9_Reviews', 'total_verified_count' ) )
			? Delicat_Builder_V9_Reviews::total_verified_count()
			: Delicat_Builder_V9_Reviews::published_count();
	}

	if ( empty( $items ) ) {
		return '';
	}

	$vars = array(
		'--dbv9-test-bg:' . sanitize_hex_color( $content['card_bg'] ?? '#0a0a23' ),
		'--dbv9-test-border:' . sanitize_hex_color( $content['card_border'] ?? '#25264d' ),
		'--dbv9-test-quote:' . sanitize_hex_color( $content['quote_color'] ?? '#5964ff' ),
		'--dbv9-test-text:' . sanitize_hex_color( $content['text_color'] ?? '#989bad' ),
		'--dbv9-test-name:' . sanitize_hex_color( $content['name_color'] ?? '#f8f8ff' ),
		'--dbv9-test-stars:' . sanitize_hex_color( $content['stars_color'] ?? '#ffbf2f' ),
		'--dbv9-test-gap:' . absint( $content['card_gap'] ?? 24 ) . 'px',
		'--dbv9-test-width-d:' . absint( $content['card_width_d'] ?? 560 ) . 'px',
		'--dbv9-test-width-m:' . absint( $content['card_width_m'] ?? 76 ) . 'vw',
	);

	$out = '<div class="delicat-testimonials" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= self::info_heading( $content, 'delicat-testimonials' );

	if ( '' !== (string) ( $content['rating_value'] ?? '' ) ) {
		$out .= '<div class="delicat-testimonials__rating">';
		$out .= '<strong>' . esc_html( (string) $content['rating_value'] ) . '</strong>';
		$out .= '<span class="delicat-testimonials__rating-stars" aria-hidden="true">★★★★★</span>';
		$rating_count = absint( $content['rating_count'] ?? 0 ) + $approved_count;
		if ( $rating_count > 0 || ! empty( $content['rating_label'] ) ) {
			$out .= '<span class="delicat-testimonials__rating-label">' . esc_html( trim( $rating_count . ' ' . (string) ( $content['rating_label'] ?? '' ) ) ) . '</span>';
		}
		$out .= '</div>';
	}

	/* RC71: two auto-scrolling rows — top drifts right, bottom drifts left.
	 * The initial HTML carries each review only once. core.js clones the row
	 * only when this below-fold section approaches the viewport, cutting the
	 * homepage's initial testimonial DOM roughly in half while preserving the
	 * seamless CSS loop and accessibility. Reduced-motion users never need the
	 * clone because their rows are manually scrollable. */
	$rows = array( array(), array() );
	$rix  = 0;
	foreach ( $items as $item ) {
		$rows[ $rix % 2 ][] = $item;
		$rix++;
	}
	if ( empty( $rows[1] ) ) {
		$rows[1] = array_reverse( $rows[0] );
	}
	$mcard = static function ( array $item, bool $hidden ) use ( $link_products ): string {
		$initials = '';
		foreach ( array_slice( (array) preg_split( '/\s+/', trim( (string) $item['name'] ) ), 0, 2 ) as $word ) {
			if ( '' !== $word ) {
				$initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
			}
		}
		$initials = strtoupper( '' !== $initials ? $initials : 'DS' );
		$html  = '<article class="delicat-testimonials__mcard"' . ( $hidden ? ' aria-hidden="true"' : ' role="listitem"' ) . '>';
		$html .= '<span class="delicat-testimonials__mstars" aria-label="' . esc_attr( sprintf( __( '%d out of 5 stars', 'delicat-builder-v9' ), $item['rating'] ) ) . '">' . esc_html( str_repeat( '★', max( 1, (int) $item['rating'] ) ) ) . '</span>';
		$html .= '<p class="delicat-testimonials__mquote">“' . esc_html( $item['quote'] ) . '”</p>';
		$html .= '<footer class="delicat-testimonials__mfoot"><span class="delicat-testimonials__mavatar" aria-hidden="true">' . esc_html( $initials ) . '</span>';
		$html .= '<span class="delicat-testimonials__mid"><strong>' . esc_html( $item['name'] ) . '</strong>';
		/* RC63: the reviewed WooCommerce product is a link on the card. When
		 * the review carries no city, the product name is the second line
		 * itself (linked); otherwise city and product are both shown. */
		$product = (string) ( $item['product'] ?? '' );
		$url     = $link_products ? (string) ( $item['url'] ?? '' ) : '';
		$place   = (string) $item['place'];
		if ( '' !== $url && '' !== $product && $place === $product ) {
			$html .= '<a class="delicat-testimonials__mproduct" href="' . esc_url( $url ) . '" data-delicat-prefetch' . ( $hidden ? ' tabindex="-1"' : '' ) . '>' . esc_html( $product ) . '</a>';
		} else {
			if ( '' !== $place ) {
				$html .= '<span>' . esc_html( $place ) . '</span>';
			}
			if ( '' !== $url ) {
				$html .= '<a class="delicat-testimonials__mproduct" href="' . esc_url( $url ) . '" data-delicat-prefetch' . ( $hidden ? ' tabindex="-1"' : '' ) . '>' . esc_html( '' !== $product ? $product : __( 'Voir le produit', 'delicat-builder-v9' ) ) . '</a>';
			}
		}
		$html .= '</span><svg class="delicat-testimonials__mcheck" width="18" height="18" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M6 10.4l2.6 2.6L14 7.6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></footer></article>';
		return $html;
	};
	$out .= '<div class="delicat-testimonials__marquees" role="list" aria-label="' . esc_attr__( 'Customer testimonials', 'delicat-builder-v9' ) . '">';
	foreach ( array( 'right' => $rows[0], 'left' => $rows[1] ) as $mdir => $row_items ) {
		$out .= '<div class="delicat-testimonials__marquee delicat-testimonials__marquee--' . $mdir . '"><div class="delicat-testimonials__mtrack" data-dbv9-marquee-clone>';
		foreach ( $row_items as $item ) {
			$out .= $mcard( $item, false );
		}
		$out .= '</div></div>';
	}
	$out .= '</div>';

	if ( ! empty( $content['review_title'] ) || ! empty( $content['review_button'] ) ) {
		$out .= '<div class="delicat-testimonials__review-cta">';
		if ( ! empty( $content['review_title'] ) ) {
			$out .= '<strong>' . esc_html( $content['review_title'] ) . '</strong>';
		}
		if ( ! empty( $content['review_text'] ) ) {
			$out .= '<p>' . esc_html( $content['review_text'] ) . '</p>';
		}
		if ( ! empty( $content['review_button'] ) ) {
			/* RC51.31: the CTA is a real <button> wired to the review popup —
			   no URL underneath, so nothing can fall through to WhatsApp.
			   Markup identical for everyone: cache-safe. */
			$out .= '<button type="button" data-dlc-review-open>' . self::icon_markup( 'chat' ) . '<span>' . esc_html( $content['review_button'] ) . '</span></button>';
		}
		/* RC63: Google Business Profile review link — the stars Google shows
		 * next to the store's name come from there, not from this page. */
		$google_url = esc_url( (string) ( $content['google_review_url'] ?? '' ) );
		if ( '' !== $google_url ) {
			$google_label = trim( (string) ( $content['google_button'] ?? '' ) );
			$out .= '<a class="delicat-testimonials__google" href="' . $google_url . '" target="_blank" rel="noopener nofollow">' . self::icon_markup( 'star' ) . '<span>' . esc_html( '' !== $google_label ? $google_label : __( 'Noter sur Google', 'delicat-builder-v9' ) ) . '</span></a>';
		}
		$out .= '</div>';
	}

	$out .= '</div>';
	return $out;
}

private static function faq( array $content ): string {
	$content['title'] = self::upgraded_default( $content['title'] ?? '', array( 'QUESTIONS FRÉQUENTES ❓' ), 'Questions fréquentes' );
	$content['items'] = self::upgraded_default(
		$content['items'] ?? '',
		array( "Combien de temps prend une recharge ?|La plupart des recharges sont créditées en moins d’une minute. Pour les commandes manuelles, comptez 5 à 15 minutes maximum.\nQuels moyens de paiement acceptez-vous ?|MonCash, transfert bancaire, PayPal, Wise et cartes prépayées. Les prix affichés sont en gourdes (G).\nEst-ce risqué pour mon compte de jeu ?|Nous utilisons uniquement les informations nécessaires à la livraison. Ne transmettez jamais un mot de passe au support ; si une fiche produit exige exceptionnellement une information sensible, utilisez uniquement son champ sécurisé dédié.\nComment recevoir mon code de carte cadeau ?|Après confirmation du paiement, le code est livré dans votre historique de commande selon le produit acheté." ),
		"Combien de temps prend une recharge ?|Le délai dépend du produit. Les recharges automatiques sont généralement livrées rapidement après confirmation, tandis que les commandes manuelles affichent leur délai avant l’achat.\nQuels moyens de paiement acceptez-vous ?|MonCash, NatCash et les autres moyens affichés au moment du paiement. Les prix sont indiqués avant validation.\nEst-ce risqué pour mon compte de jeu ?|Nous utilisons uniquement les informations nécessaires à la livraison. Ne transmettez jamais un mot de passe au support ; si une fiche produit exige exceptionnellement une information sensible, utilisez uniquement son champ sécurisé dédié.\nComment recevoir mon code de carte cadeau ?|Après confirmation du paiement, le code est livré dans votre historique de commande selon le produit acheté."
	);
	$lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['items'] ?? '' ) );
	$items = array();
	foreach ( array_slice( (array) $lines, 0, 12 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( count( $parts ) < 2 || '' === $parts[0] ) {
			continue;
		}
		$items[] = array(
			'question' => sanitize_text_field( $parts[0] ),
			'answer'   => sanitize_text_field( $parts[1] ),
		);
	}
	if ( empty( $items ) ) {
		return '';
	}

	$open = max( 0, min( count( $items ) - 1, absint( $content['open_index'] ?? 0 ) ) );
	$vars = array(
		'--dbv9-faq-bg:' . sanitize_hex_color( $content['card_bg'] ?? '#0a0a23' ),
		'--dbv9-faq-border:' . sanitize_hex_color( $content['card_border'] ?? '#25264d' ),
		'--dbv9-faq-question:' . sanitize_hex_color( $content['question_color'] ?? '#f8f8ff' ),
		'--dbv9-faq-answer:' . sanitize_hex_color( $content['answer_color'] ?? '#989bad' ),
		'--dbv9-faq-accent:' . sanitize_hex_color( $content['accent_color'] ?? '#5964ff' ),
		'--dbv9-faq-gap:' . absint( $content['card_gap'] ?? 14 ) . 'px',
		'--dbv9-faq-radius:' . absint( $content['card_radius'] ?? 28 ) . 'px',
	);

	$out = '<div class="delicat-faq" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= self::info_heading( $content, 'delicat-faq' );
	$out .= '<div class="delicat-faq__list">';
	foreach ( $items as $index => $item ) {
		$out .= '<details class="delicat-faq__item"' . ( $index === $open ? ' open' : '' ) . '>';
		$out .= '<summary><span>' . esc_html( $item['question'] ) . '</span><i aria-hidden="true"></i></summary>';
		$out .= '<div class="delicat-faq__answer"><p>' . esc_html( $item['answer'] ) . '</p></div>';
		$out .= '</details>';
	}
	$out .= '</div></div>';
	return $out;
}


private static function why_delicat( array $content ): string {
	/* RC58: reference look (Ultra Fast "Pourquoi Delicat") by default — see how_it_works(). */
	$style = in_array( (string) ( $content['style'] ?? 'ultra' ), array( 'ultra', 'neon' ), true ) ? (string) ( $content['style'] ?? 'ultra' ) : 'ultra';
	if ( 'ultra' === $style ) {
		$content['eyebrow'] = self::upgraded_default( $content['eyebrow'] ?? '', array( 'POURQUOI DELICAT STORE' ), 'Pourquoi Delicat' );
		$content['title']   = self::sentence_case( self::upgraded_default( $content['title'] ?? '', array( "LA CONFIANCE D'UNE VRAIE PLATEFORME", 'LA CONFIANCE D’UNE VRAIE PLATEFORME', 'Tout est pensé pour ta tranquillité 💜' ), 'Tout est pensé pour ta tranquillité' ) );
		$content['items']   = self::upgraded_default(
			$content['items'] ?? '',
			array( "lightning|LIVRAISON INSTANTANÉE|Codes et recharges activés en moins de 5 minutes.\nshield|PAIEMENT SÉCURISÉ|MonCash, Natcash, Visa et Mastercard chiffrés.\nglobe|DIASPORA FRIENDLY|Offrez une recharge à vos proches en Haïti, où que vous soyez.\nheadset|SUPPORT 24/7|Une équipe humaine, en français et en créole.\nverified|PRODUITS OFFICIELS|Comptes et codes provenant de fournisseurs vérifiés.\ntag|TARIFS EN GOURDES|Des prix justes, clairs, sans frais cachés.", "lightning|Livraison instantanée|vos recharges automatiques sont livres en moins de 5s\nshield|Paiement sécurisé|MonCash, Natcash,.\nverified|Produits officiels|Comptes et codes provenant de fournisseurs vérifiés.\ntag|Tarifs en gourdes|Des prix justes, clairs, sans frais cachés." ),
			"bolt|Livraison vérifiée|Chaque commande est validée avant son envoi numérique.\nshield|Paiement protégé|Prix, stock et paiement sont contrôlés côté serveur.\nglobe|Haïti & diaspora|Recharge pour toi ou pour un proche, où que tu sois.\nheadset|Support humain|Une équipe disponible pour t’accompagner 7j/7."
		);
		if ( ! array_key_exists( 'subtitle', $content ) ) {
			$content['subtitle'] = 'Une plateforme rapide, des informations claires et des commandes contrôlées.';
		}
		$content = self::reference_title_sizes( $content );
		if ( ! array_key_exists( 'cta_title', $content ) ) {
			$content['cta_eyebrow'] = 'Haïti · Diaspora';
			$content['cta_icon']    = 'globe';
			$content['cta_title']   = 'Fais plaisir à un proche, simplement.';
			$content['cta_text']    = 'Jeux, abonnements et cartes cadeaux : choisis le service, règle en toute sécurité, puis suis la livraison dans ton espace client.';
			$content['cta_button']  = 'Découvrir les services';
			$content['cta_url']     = '/shop/';
		}
	}
	$feature_lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['items'] ?? '' ) );
	$features = array();
	foreach ( array_slice( (array) $feature_lines, 0, 8 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 3 ) );
		if ( count( $parts ) < 3 || '' === $parts[1] ) {
			continue;
		}
		$legacy_delivery_feature = preg_match( '/livraison\s+instantan/iu', (string) $parts[1] )
			|| preg_match( '/moins\s+de\s+(?:5\s*s|5\s+minutes)|recharges?\s+en\s+1\s*min/iu', (string) $parts[2] );
		if ( $legacy_delivery_feature ) {
			$parts[0] = 'bolt';
			$parts[1] = 'Livraison vérifiée';
			$parts[2] = 'Le délai dépend du produit et l’état de la commande reste visible dans votre espace client.';
		}
		$features[] = array(
			'icon'  => sanitize_key( $parts[0] ),
			'title' => 'ultra' === $style ? self::sentence_case( sanitize_text_field( $parts[1] ) ) : sanitize_text_field( $parts[1] ),
			'text'  => sanitize_text_field( $parts[2] ),
		);
	}
	if ( empty( $features ) ) {
		return '';
	}

	if ( 'ultra' === $style ) {
		return self::why_delicat_ultra( $content, $features );
	}

	$payment_lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['payments'] ?? '' ) );
	$payments = array();
	foreach ( array_slice( (array) $payment_lines, 0, 8 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 2 ) );
		if ( count( $parts ) < 2 || '' === $parts[1] ) {
			continue;
		}
		$payments[] = array(
			'icon'  => sanitize_key( $parts[0] ),
			'label' => sanitize_text_field( $parts[1] ),
		);
	}

	$vars = array(
		'--dbv9-why-eyebrow:' . sanitize_hex_color( $content['eyebrow_color'] ?? '#6a64ff' ),
		'--dbv9-why-title:' . sanitize_hex_color( $content['title_color'] ?? '#f8f8ff' ),
		'--dbv9-why-bg:' . sanitize_hex_color( $content['card_bg'] ?? '#0a0a23' ),
		'--dbv9-why-border:' . sanitize_hex_color( $content['card_border'] ?? '#25264d' ),
		'--dbv9-why-card-title:' . sanitize_hex_color( $content['card_title'] ?? '#f8f8ff' ),
		'--dbv9-why-card-text:' . sanitize_hex_color( $content['card_text'] ?? '#989bad' ),
		'--dbv9-why-radius:' . absint( $content['card_radius'] ?? 28 ) . 'px',
		'--dbv9-why-gap:' . absint( $content['card_gap'] ?? 14 ) . 'px',
		'--dbv9-why-title-d:' . absint( $content['title_size_d'] ?? 38 ) . 'px',
		'--dbv9-why-title-t:' . absint( $content['title_size_t'] ?? 36 ) . 'px',
		'--dbv9-why-title-m:' . absint( $content['title_size_m'] ?? 28 ) . 'px',
	);

	$out = '<div class="delicat-why" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= '<header class="delicat-why__head">';
	if ( ! empty( $content['eyebrow'] ) ) {
		$out .= '<span class="delicat-why__eyebrow">' . esc_html( $content['eyebrow'] ) . '</span>';
	}
	if ( ! empty( $content['title'] ) ) {
		$out .= '<h2 class="delicat-why__title">' . esc_html( $content['title'] ) . '</h2>';
	}
	$out .= '</header>';

	$out .= '<div class="delicat-why__list">';
	foreach ( $features as $index => $feature ) {
		$tone = ( $index % 6 ) + 1;
		$out .= '<article class="delicat-why__card delicat-why__card--' . esc_attr( (string) $tone ) . '">';
		$out .= '<div class="delicat-why__icon">' . self::icon_markup( $feature['icon'] ) . '</div>';
		$out .= '<div><h3>' . esc_html( $feature['title'] ) . '</h3><p>' . esc_html( $feature['text'] ) . '</p></div>';
		$out .= '</article>';
	}
	$out .= '</div>';

	if ( ! empty( $payments ) ) {
		$out .= '<div class="delicat-why__payments">';
		if ( ! empty( $content['payment_title'] ) ) {
			$out .= '<strong class="delicat-why__payments-title">' . esc_html( $content['payment_title'] ) . '</strong>';
		}
		$out .= '<div class="delicat-why__payments-grid">';
		foreach ( $payments as $payment ) {
			$out .= '<div class="delicat-why__payment">' . self::icon_markup( $payment['icon'] ) . '<span>' . esc_html( $payment['label'] ) . '</span></div>';
		}
		$out .= '</div>';
		if ( ! empty( $content['payment_note'] ) ) {
			$out .= '<p class="delicat-why__payment-note">' . esc_html( $content['payment_note'] ) . '</p>';
		}
		$out .= '</div>';
	}

	$out .= '</div>';
	return $out;
}

private static function why_delicat_ultra( array $content, array $features ): string {
	$tones = array(
		array( 'bg' => '#ececff', 'fg' => '#6a5cff' ),
		array( 'bg' => '#e6f7ee', 'fg' => '#22a06b' ),
		array( 'bg' => '#fbe6f0', 'fg' => '#e0407f' ),
		array( 'bg' => '#ececff', 'fg' => '#6a5cff' ),
		array( 'bg' => '#fff3d6', 'fg' => '#d9860c' ),
		array( 'bg' => '#e3f3ff', 'fg' => '#1f7fd6' ),
	);
	$vars = array(
		'--dbv9-why-eyebrow:' . sanitize_hex_color( $content['eyebrow_color'] ?? '#6a5cff' ),
		'--dbv9-why-title:' . sanitize_hex_color( $content['ultra_title'] ?? '#111827' ),
		'--dbv9-why-subtitle:' . sanitize_hex_color( $content['ultra_text'] ?? '#6b7280' ),
		'--dbv9-why-bg:' . sanitize_hex_color( $content['ultra_bg'] ?? '#ffffff' ),
		'--dbv9-why-border:' . sanitize_hex_color( $content['ultra_border'] ?? '#e8e6f7' ),
		'--dbv9-why-card-title:' . sanitize_hex_color( $content['ultra_title'] ?? '#111827' ),
		'--dbv9-why-card-text:' . sanitize_hex_color( $content['ultra_text'] ?? '#6b7280' ),
		'--dbv9-why-radius:' . max( 8, min( 48, absint( $content['ultra_radius'] ?? 14 ) ) ) . 'px',
		'--dbv9-why-gap:' . absint( $content['card_gap'] ?? 14 ) . 'px',
		'--dbv9-why-title-d:' . absint( $content['title_size_d'] ?? 38 ) . 'px',
		'--dbv9-why-title-t:' . absint( $content['title_size_t'] ?? 36 ) . 'px',
		'--dbv9-why-title-m:' . absint( $content['title_size_m'] ?? 28 ) . 'px',
		'--dbv9-why-cta-a:' . sanitize_hex_color( $content['cta_bg_start'] ?? '#ecebff' ),
		'--dbv9-why-cta-b:' . sanitize_hex_color( $content['cta_bg_end'] ?? '#fde7f0' ),
		'--dbv9-why-cta-btn-a:' . sanitize_hex_color( $content['cta_button_start'] ?? '#5b5bd6' ),
		'--dbv9-why-cta-btn-b:' . sanitize_hex_color( $content['cta_button_end'] ?? '#e0407f' ),
	);

	$out = '<div class="delicat-why delicat-why--ultra" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= '<header class="delicat-why__head">';
	if ( ! empty( $content['eyebrow'] ) ) {
		$out .= '<span class="delicat-why__eyebrow">' . esc_html( $content['eyebrow'] ) . '</span>';
	}
	if ( ! empty( $content['title'] ) ) {
		$out .= '<h2 class="delicat-why__title">' . esc_html( $content['title'] ) . '</h2>';
	}
	if ( ! empty( $content['subtitle'] ) ) {
		$out .= '<p class="delicat-why__subtitle">' . esc_html( $content['subtitle'] ) . '</p>';
	}
	$out .= '</header>';

	$out .= '<div class="delicat-why__list">';
	foreach ( $features as $index => $feature ) {
		$tone = $tones[ $index % count( $tones ) ];
		$out .= '<article class="delicat-why__card" style="' . esc_attr( '--dbv9-why-tone-bg:' . $tone['bg'] . ';--dbv9-why-tone:' . $tone['fg'] ) . '">';
		$out .= '<div class="delicat-why__icon">' . self::icon_markup( $feature['icon'] ) . '</div>';
		$out .= '<h3>' . esc_html( $feature['title'] ) . '</h3><p>' . esc_html( $feature['text'] ) . '</p>';
		$out .= '</article>';
	}
	$out .= '</div>';

	if ( ! empty( $content['cta_title'] ) ) {
		$out .= '<div class="delicat-why__cta">';
		if ( ! empty( $content['cta_eyebrow'] ) ) {
			$out .= '<span class="delicat-why__cta-eyebrow">' . self::icon_markup( (string) ( $content['cta_icon'] ?? 'globe' ) ) . '<span>' . esc_html( $content['cta_eyebrow'] ) . '</span></span>';
		}
		$out .= '<h3 class="delicat-why__cta-title">' . esc_html( $content['cta_title'] ) . '</h3>';
		if ( ! empty( $content['cta_text'] ) ) {
			$out .= '<p class="delicat-why__cta-text">' . esc_html( $content['cta_text'] ) . '</p>';
		}
		$url = esc_url( (string) ( $content['cta_url'] ?? '' ) );
		if ( ! empty( $content['cta_button'] ) && '' !== $url ) {
			$out .= '<a class="delicat-why__cta-button" href="' . $url . '" data-delicat-prefetch><span>' . esc_html( $content['cta_button'] ) . '</span><span aria-hidden="true">→</span></a>';
		}
		$out .= '</div>';
	}

	$out .= '</div>';
	return $out;
}

/**
 * RC58 "Retrouve ce que tu aimes ✨" — a fully customisable grid of link
 * cards (icon token or emoji | title | text | URL | optional hex tone), with
 * an optional closing button. Server-rendered, no JavaScript.
 */
private static function favorites( array $content ): string {
	$lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['items'] ?? '' ) );
	$items = array();
	foreach ( array_slice( (array) $lines, 0, 12 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 5 ) );
		if ( count( $parts ) < 2 || '' === $parts[1] ) {
			continue;
		}
		$items[] = array(
			'icon'  => sanitize_text_field( $parts[0] ),
			'title' => sanitize_text_field( $parts[1] ),
			'text'  => sanitize_text_field( $parts[2] ?? '' ),
			'url'   => esc_url( (string) ( $parts[3] ?? '' ) ),
			'tone'  => sanitize_hex_color( (string) ( $parts[4] ?? '' ) ) ?: '',
		);
	}
	if ( empty( $items ) ) {
		return '';
	}

	$tones = array( '#6a5cff', '#e0407f', '#22a06b', '#d9860c', '#1f7fd6', '#8b5cf6' );
	$vars = array(
		'--dbv9-fav-eyebrow:' . sanitize_hex_color( $content['eyebrow_color'] ?? '#6a5cff' ),
		'--dbv9-fav-title:' . sanitize_hex_color( $content['title_color'] ?? '#111827' ),
		'--dbv9-fav-subtitle:' . sanitize_hex_color( $content['subtitle_color'] ?? '#6b7280' ),
		'--dbv9-fav-bg:' . sanitize_hex_color( $content['card_bg'] ?? '#ffffff' ),
		'--dbv9-fav-border:' . sanitize_hex_color( $content['card_border'] ?? '#e8e6f7' ),
		'--dbv9-fav-card-title:' . sanitize_hex_color( $content['card_title'] ?? '#111827' ),
		'--dbv9-fav-card-text:' . sanitize_hex_color( $content['card_text'] ?? '#6b7280' ),
		'--dbv9-fav-radius:' . max( 8, min( 48, absint( $content['card_radius'] ?? 14 ) ) ) . 'px',
		'--dbv9-fav-gap:' . max( 0, min( 40, absint( $content['card_gap'] ?? 14 ) ) ) . 'px',
		'--dbv9-fav-cols-d:' . max( 2, min( 4, absint( $content['columns_d'] ?? 4 ) ) ),
		'--dbv9-fav-cols-m:' . max( 1, min( 2, absint( $content['columns_m'] ?? 2 ) ) ),
		'--dbv9-fav-icon-size:' . max( 36, min( 80, absint( $content['icon_size'] ?? 44 ) ) ) . 'px',
		'--dbv9-fav-title-d:' . absint( $content['title_size_d'] ?? 34 ) . 'px',
		'--dbv9-fav-title-t:' . absint( $content['title_size_t'] ?? 30 ) . 'px',
		'--dbv9-fav-title-m:' . absint( $content['title_size_m'] ?? 26 ) . 'px',
		'--dbv9-fav-btn-a:' . sanitize_hex_color( $content['button_start'] ?? '#5b5bd6' ),
		'--dbv9-fav-btn-b:' . sanitize_hex_color( $content['button_end'] ?? '#e0407f' ),
	);
	$align = in_array( (string) ( $content['align'] ?? 'left' ), array( 'left', 'center' ), true ) ? (string) $content['align'] : 'left';

	$out = '<div class="delicat-fav delicat-fav--' . esc_attr( $align ) . '" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= '<header class="delicat-fav__head">';
	if ( ! empty( $content['eyebrow'] ) ) {
		$out .= '<span class="delicat-fav__eyebrow">' . esc_html( $content['eyebrow'] ) . '</span>';
	}
	if ( ! empty( $content['title'] ) ) {
		$out .= '<h2 class="delicat-fav__title">' . esc_html( $content['title'] ) . '</h2>';
	}
	if ( ! empty( $content['subtitle'] ) ) {
		$out .= '<p class="delicat-fav__subtitle">' . esc_html( $content['subtitle'] ) . '</p>';
	}
	$out .= '</header><div class="delicat-fav__grid">';
	foreach ( $items as $index => $item ) {
		$tone = $item['tone'] ?: $tones[ $index % count( $tones ) ];
		$tag  = '' !== $item['url'] ? 'a' : 'div';
		$attr = '' !== $item['url'] ? ' href="' . $item['url'] . '" data-delicat-prefetch' : '';
		$out .= '<' . $tag . ' class="delicat-fav__card" style="' . esc_attr( '--dbv9-fav-tone:' . $tone ) . '"' . $attr . '>';
		$out .= '<span class="delicat-fav__icon" aria-hidden="true">' . self::icon_markup( $item['icon'] ) . '</span>';
		$out .= '<span class="delicat-fav__body"><strong>' . esc_html( $item['title'] ) . '</strong>';
		if ( '' !== $item['text'] ) {
			$out .= '<span>' . esc_html( $item['text'] ) . '</span>';
		}
		$out .= '</span>';
		if ( '' !== $item['url'] ) {
			$out .= '<span class="delicat-fav__arrow" aria-hidden="true">→</span>';
		}
		$out .= '</' . $tag . '>';
	}
	$out .= '</div>';
	$button_url = esc_url( (string) ( $content['button_url'] ?? '' ) );
	if ( ! empty( $content['button_text'] ) && '' !== $button_url ) {
		$out .= '<div class="delicat-fav__footer"><a class="delicat-fav__button" href="' . $button_url . '" data-delicat-prefetch><span>' . esc_html( $content['button_text'] ) . '</span><span aria-hidden="true">→</span></a></div>';
	}
	$out .= '</div>';
	return $out;
}

/**
 * RC61 "Programme Bon Kliyan" — dark loyalty panel with a crown eyebrow,
 * title, text, lime button, optional second button and perks, and a
 * decorative membership-card stack (or a media-library image). Every
 * colour, size and text is a section field. Server-rendered, no JavaScript;
 * the card float is CSS keyframes only and identical markup for every visitor.
 */
/** RC61: a blanked colour field falls back to the section default instead of printing an empty custom property. */
private static function color_or( $value, string $default ): string {
	$hex = sanitize_hex_color( trim( (string) $value ) );
	return is_string( $hex ) && '' !== $hex ? $hex : $default;
}

private static function bon_kliyan( array $content ): string {
	$title    = trim( (string) ( $content['title'] ?? '' ) );
	$subtitle = trim( (string) ( $content['subtitle'] ?? '' ) );
	$eyebrow  = trim( (string) ( $content['eyebrow'] ?? '' ) );
	if ( '' === $title && '' === $subtitle && '' === $eyebrow ) {
		return '';
	}

	$layout = 'stack' === ( $content['layout'] ?? 'auto' ) ? 'stack' : 'auto';
	$align  = 'left' === ( $content['align'] ?? 'center' ) ? 'left' : 'center';
	$visual = sanitize_key( (string) ( $content['visual'] ?? 'cards' ) );
	if ( ! in_array( $visual, array( 'cards', 'image', 'none' ), true ) ) {
		$visual = 'cards';
	}
	$weight = absint( $content['title_weight'] ?? 600 );
	if ( ! in_array( $weight, array( 400, 500, 600, 700, 800 ), true ) ) {
		$weight = 600;
	}
	$glow_strength = max( 0, min( 100, absint( $content['glow_strength'] ?? 60 ) ) );

	$vars = array(
		'--dbv9-bk-bg:' . self::color_or( $content['panel_bg'] ?? '', '#12131f' ),
		'--dbv9-bk-border:' . self::color_or( $content['panel_border'] ?? '', '#2a2b45' ),
		'--dbv9-bk-radius:' . max( 8, min( 48, absint( $content['panel_radius'] ?? 30 ) ) ) . 'px',
		'--dbv9-bk-glow:' . self::color_or( $content['glow_color'] ?? '', '#6a4dff' ),
		'--dbv9-bk-glow-a:' . ( $glow_strength / 100 ),
		'--dbv9-bk-eyebrow:' . self::color_or( $content['eyebrow_color'] ?? '', '#d6ff3f' ),
		'--dbv9-bk-title:' . self::color_or( $content['title_color'] ?? '', '#ffffff' ),
		'--dbv9-bk-subtitle:' . self::color_or( $content['subtitle_color'] ?? '', '#a9adc4' ),
		'--dbv9-bk-btn-bg:' . self::color_or( $content['button_bg'] ?? '', '#d6ff3f' ),
		'--dbv9-bk-btn-text:' . self::color_or( $content['button_text_color'] ?? '', '#12131f' ),
		'--dbv9-bk-title-d:' . max( 20, min( 72, absint( $content['title_size_d'] ?? 46 ) ) ) . 'px',
		'--dbv9-bk-title-t:' . max( 20, min( 64, absint( $content['title_size_t'] ?? 40 ) ) ) . 'px',
		'--dbv9-bk-title-m:' . max( 18, min( 52, absint( $content['title_size_m'] ?? 34 ) ) ) . 'px',
		'--dbv9-bk-title-weight:' . $weight,
		'--dbv9-bk-card-bg:' . self::color_or( $content['card_bg'] ?? '', '#1b1c2e' ),
		'--dbv9-bk-card-text:' . self::color_or( $content['card_text_color'] ?? '', '#ffffff' ),
		'--dbv9-bk-card-accent:' . self::color_or( $content['card_accent'] ?? '', '#d6ff3f' ),
		'--dbv9-bk-card-back:' . self::color_or( $content['card_back_bg'] ?? '', '#6f5cff' ),
	);

	/* The visual is built first: an image visual with no usable attachment
	 * falls back to the no-visual layout instead of leaving an empty column. */
	$visual_markup = '';
	if ( 'image' === $visual ) {
		$image = Delicat_Builder_V9_Media::image( absint( $content['visual_image_id'] ?? 0 ), 'large', 'delicat-bk__image', false, '(max-width:960px) 100vw, 40vw' );
		if ( $image ) {
			$visual_markup = '<div class="delicat-bk__visual delicat-bk__visual--image">' . $image . '</div>';
		} else {
			$visual = 'none';
		}
	} elseif ( 'cards' === $visual ) {
		$card_eyebrow = trim( (string) ( $content['card_eyebrow'] ?? '' ) );
		$card_title   = trim( (string) ( $content['card_title'] ?? '' ) );
		$card_icon    = trim( (string) ( $content['card_icon'] ?? '' ) );
		$back_text    = trim( (string) ( $content['card_back_text'] ?? '' ) );
		$badge_icon   = trim( (string) ( $content['badge_icon'] ?? '' ) );
		$visual_markup = '<div class="delicat-bk__visual delicat-bk__visual--cards" aria-hidden="true"><div class="delicat-bk__stage">';
		if ( ! isset( $content['rings_enabled'] ) || ! empty( $content['rings_enabled'] ) ) {
			$visual_markup .= '<span class="delicat-bk__ring delicat-bk__ring--1"></span><span class="delicat-bk__ring delicat-bk__ring--2"></span>';
		}
		$visual_markup .= '<span class="delicat-bk__card delicat-bk__card--back">';
		if ( '' !== $back_text ) {
			$visual_markup .= '<span class="delicat-bk__card-brand">' . esc_html( $back_text ) . '</span>';
		}
		$visual_markup .= '</span>';
		$visual_markup .= '<span class="delicat-bk__card delicat-bk__card--front">';
		if ( '' !== $card_eyebrow ) {
			$visual_markup .= '<span class="delicat-bk__card-eyebrow">' . esc_html( $card_eyebrow ) . '</span>';
		}
		if ( '' !== $card_title ) {
			$visual_markup .= '<span class="delicat-bk__card-title">' . esc_html( $card_title ) . '</span>';
		}
		if ( '' !== $card_icon ) {
			$visual_markup .= '<span class="delicat-bk__card-icon">' . self::icon_markup( $card_icon ) . '</span>';
		}
		$visual_markup .= '<span class="delicat-bk__card-sheen"></span>';
		$visual_markup .= '</span>';
		if ( ( ! isset( $content['badge_enabled'] ) || ! empty( $content['badge_enabled'] ) ) && '' !== $badge_icon ) {
			$visual_markup .= '<span class="delicat-bk__badge">' . self::icon_markup( $badge_icon ) . '</span>';
		}
		$visual_markup .= '</div></div>';
	}

	$classes = array( 'delicat-bk', 'delicat-bk--' . $layout, 'delicat-bk--' . $align, 'delicat-bk--visual-' . $visual );
	if ( ! isset( $content['pattern_enabled'] ) || ! empty( $content['pattern_enabled'] ) ) {
		$classes[] = 'has-pattern';
	}
	if ( ! isset( $content['motion_enabled'] ) || ! empty( $content['motion_enabled'] ) ) {
		$classes[] = 'has-motion';
	}

	$out = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= '<div class="delicat-bk__copy">';
	if ( '' !== $eyebrow ) {
		$eyebrow_icon = trim( (string) ( $content['eyebrow_icon'] ?? '' ) );
		$out .= '<span class="delicat-bk__eyebrow">' . ( '' !== $eyebrow_icon ? self::icon_markup( $eyebrow_icon ) : '' ) . '<span>' . esc_html( $eyebrow ) . '</span></span>';
	}
	if ( '' !== $title ) {
		$out .= '<h2 class="delicat-bk__title">' . esc_html( $title ) . '</h2>';
	}
	if ( '' !== $subtitle ) {
		$out .= '<p class="delicat-bk__subtitle">' . esc_html( $subtitle ) . '</p>';
	}

	$button_url   = esc_url( (string) ( $content['button_url'] ?? '' ) );
	$button_2_url = esc_url( (string) ( $content['button_2_url'] ?? '' ) );
	$has_button   = ! empty( $content['button_text'] ) && '' !== $button_url;
	$has_button_2 = ! empty( $content['button_2_text'] ) && '' !== $button_2_url;
	if ( $has_button || $has_button_2 ) {
		$out .= '<div class="delicat-bk__actions">';
		if ( $has_button ) {
			$out .= '<a class="delicat-bk__button" href="' . $button_url . '" data-delicat-prefetch><span>' . esc_html( $content['button_text'] ) . '</span><span class="delicat-bk__button-arrow" aria-hidden="true">→</span></a>';
		}
		if ( $has_button_2 ) {
			$out .= '<a class="delicat-bk__button delicat-bk__button--ghost" href="' . $button_2_url . '" data-delicat-prefetch><span>' . esc_html( $content['button_2_text'] ) . '</span></a>';
		}
		$out .= '</div>';
	}

	$perk_lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['perks'] ?? '' ) );
	$perks = array();
	foreach ( array_slice( (array) $perk_lines, 0, 6 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 3 ) );
		if ( count( $parts ) < 2 || '' === $parts[1] ) {
			continue;
		}
		$perks[] = array(
			'icon'  => sanitize_text_field( $parts[0] ),
			'title' => sanitize_text_field( $parts[1] ),
			'text'  => sanitize_text_field( $parts[2] ?? '' ),
		);
	}
	if ( ! empty( $perks ) ) {
		$out .= '<ul class="delicat-bk__perks">';
		foreach ( $perks as $perk ) {
			$out .= '<li class="delicat-bk__perk">';
			if ( '' !== $perk['icon'] ) {
				$out .= '<span class="delicat-bk__perk-icon" aria-hidden="true">' . self::icon_markup( $perk['icon'] ) . '</span>';
			}
			$out .= '<span class="delicat-bk__perk-body"><strong>' . esc_html( $perk['title'] ) . '</strong>';
			if ( '' !== $perk['text'] ) {
				$out .= '<span>' . esc_html( $perk['text'] ) . '</span>';
			}
			$out .= '</span></li>';
		}
		$out .= '</ul>';
	}
	$out .= '</div>';
	$out .= $visual_markup;
	$out .= '</div>';
	return $out;
}

private static function newsletter( array $content ): string {
	if ( empty( $content['title'] ) && empty( $content['text'] ) ) {
		return '';
	}

	$vars = array(
		'--dbv9-news-bg:' . sanitize_hex_color( $content['panel_bg'] ?? '#0a0a23' ),
		'--dbv9-news-border:' . sanitize_hex_color( $content['panel_border'] ?? '#25264d' ),
		'--dbv9-news-title:' . sanitize_hex_color( $content['title_color'] ?? '#f8f8ff' ),
		'--dbv9-news-text:' . sanitize_hex_color( $content['text_color'] ?? '#989bad' ),
		'--dbv9-news-a1:' . sanitize_hex_color( $content['accent_start'] ?? '#5964ff' ),
		'--dbv9-news-a2:' . sanitize_hex_color( $content['accent_end'] ?? '#ee3ba8' ),
		'--dbv9-news-radius:' . absint( $content['card_radius'] ?? 28 ) . 'px',
	);

	$action = esc_url( $content['action_url'] ?? '' );
	$out = '<div class="delicat-newsletter" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	$out .= '<div class="delicat-newsletter__icon">' . self::icon_markup( $content['icon'] ?? 'mail' ) . '</div>';
	$out .= '<h2>' . esc_html( $content['title'] ?? '' ) . '</h2>';
	if ( ! empty( $content['text'] ) ) {
		$out .= '<p>' . esc_html( $content['text'] ) . '</p>';
	}

	if ( $action ) {
		$out .= '<form class="delicat-newsletter__form" action="' . $action . '" method="post" target="_self">';
		$out .= '<label class="screen-reader-text" for="delicat-newsletter-email">' . esc_html__( 'Email address', 'delicat-builder-v9' ) . '</label>';
		$out .= '<input id="delicat-newsletter-email" type="email" name="email" inputmode="email" autocomplete="email" required placeholder="' . esc_attr( $content['placeholder'] ?? 'votre@email.com' ) . '">';
		$out .= '<button type="submit">' . self::icon_markup( 'mail' ) . '<span>' . esc_html( $content['button_text'] ?? '' ) . '</span></button>';
		$out .= '</form>';
	} else {
		// Safe visual-only state until the site owner explicitly configures a
		// trusted subscription endpoint. No public Builder endpoint is exposed.
		$out .= '<div class="delicat-newsletter__form is-preview" aria-label="' . esc_attr__( 'Newsletter preview', 'delicat-builder-v9' ) . '">';
		$out .= '<div class="delicat-newsletter__fake-input">' . esc_html( $content['placeholder'] ?? 'votre@email.com' ) . '</div>';
		$out .= '<span class="delicat-newsletter__button" aria-disabled="true">' . self::icon_markup( 'mail' ) . '<span>' . esc_html( $content['button_text'] ?? '' ) . '</span></span>';
		$out .= '</div>';
	}

	$out .= '</div>';
	return $out;
}


private static function trust_strip( array $content ): string {
	$content['items'] = self::upgraded_default(
		$content['items'] ?? '',
		array( "shield|Paiements sécurisés|100% fiables\nlightning|Livraison instantanée|Recharges en 1 min\nheadset|Support 24/7|Toujours là pour vous" ),
		"shield|Paiements sécurisés|100% fiables\nlightning|Livraison rapide|Délai selon le produit\nheadset|Support humain|Disponible pour vous aider"
	);
	$lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['items'] ?? '' ) );
	$items = array();
	foreach ( array_slice( (array) $lines, 0, 3 ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line, 3 ) );
		if ( count( $parts ) < 3 || '' === $parts[1] ) {
			continue;
		}
		if ( preg_match( '/livraison\s+instantan/iu', (string) $parts[1] ) || preg_match( '/recharges?\s+en\s+1\s*min/iu', (string) $parts[2] ) ) {
			$parts[0] = 'lightning';
			$parts[1] = 'Livraison rapide';
			$parts[2] = 'Délai selon le produit';
		}
		$items[] = array(
			'icon'     => sanitize_text_field( $parts[0] ),
			'title'    => sanitize_text_field( $parts[1] ),
			'subtitle' => sanitize_text_field( $parts[2] ),
		);
	}
	if ( empty( $items ) && empty( $content['domain_label'] ) && empty( $content['footer_text'] ) ) {
		return '';
	}

	$vars = array(
		'--dbv9-trust-bg:' . sanitize_hex_color( $content['panel_bg'] ?? '#0a0a23' ),
		'--dbv9-trust-border:' . sanitize_hex_color( $content['panel_border'] ?? '#25264d' ),
		'--dbv9-trust-title:' . sanitize_hex_color( $content['title_color'] ?? '#f8f8ff' ),
		'--dbv9-trust-subtitle:' . sanitize_hex_color( $content['subtitle_color'] ?? '#989bad' ),
		'--dbv9-trust-a1:' . sanitize_hex_color( $content['accent_1'] ?? '#5964ff' ),
		'--dbv9-trust-a2:' . sanitize_hex_color( $content['accent_2'] ?? '#56d8f1' ),
		'--dbv9-trust-a3:' . sanitize_hex_color( $content['accent_3'] ?? '#ee5abd' ),
		'--dbv9-trust-status:' . sanitize_hex_color( $content['domain_status'] ?? '#2fa968' ),
		'--dbv9-trust-radius:' . absint( $content['card_radius'] ?? 28 ) . 'px',
	);

	$out = '<div class="delicat-trust" style="' . esc_attr( implode( ';', array_filter( $vars ) ) ) . '">';
	if ( ! empty( $items ) ) {
		$out .= '<div class="delicat-trust__panel">';
		foreach ( $items as $index => $item ) {
			$tone = ( $index % 3 ) + 1;
			$out .= '<div class="delicat-trust__item delicat-trust__item--' . esc_attr( (string) $tone ) . '">';
			$out .= '<span class="delicat-trust__icon" aria-hidden="true">' . self::icon_markup( $item['icon'] ) . '</span>';
			$out .= '<strong>' . esc_html( $item['title'] ) . '</strong>';
			$out .= '<span>' . esc_html( $item['subtitle'] ) . '</span>';
			$out .= '</div>';
		}
		$out .= '</div>';
	}
	if ( ! empty( $content['domain_label'] ) ) {
		$domain = '<span class="delicat-trust__lock" aria-hidden="true">' . self::icon_markup( 'lock' ) . '</span><strong>' . esc_html( $content['domain_label'] ) . '</strong><i aria-hidden="true"></i>';
		if ( ! empty( $content['domain_url'] ) ) {
			$out .= '<a class="delicat-trust__domain" href="' . esc_url( $content['domain_url'] ) . '">' . $domain . '</a>';
		} else {
			$out .= '<div class="delicat-trust__domain">' . $domain . '</div>';
		}
	}
	if ( ! empty( $content['footer_text'] ) ) {
		$out .= '<p class="delicat-trust__footer">' . esc_html( $content['footer_text'] ) . '</p>';
	}
	$out .= '</div>';
	return $out;
}

	/**
	 * RC64: category icons are modern stroke SVGs. Tokens map directly; the
	 * emoji saved by earlier reference layouts (🎮 🎁 👑 ✦ ▶ 💰 🛍 …) are
	 * translated to their token so nothing has to be re-saved; anything
	 * else prints as text. Icons are inline SVG — no font, no request.
	 */
	private static function chip_icon( string $icon ): string {
		$icon = trim( $icon );
		if ( '' === $icon ) {
			return '';
		}
		$emoji_map = array(
			'🎮' => 'gamepad', '🕹' => 'gamepad', '🎁' => 'gift', '👑' => 'crown', '✦' => 'sparkles', '✨' => 'sparkles', '⭐' => 'star', '🌟' => 'star',
			'▶' => 'tv', '📺' => 'tv', '🎬' => 'tv', '💰' => 'coins', '💵' => 'coins', '💳' => 'card', '🛍' => 'store', '🛒' => 'store', '🏪' => 'store',
			'🔒' => 'lock', '🛡' => 'shield', '⚡' => 'bolt', '📱' => 'phone', '🎧' => 'headset', '🌍' => 'globe', '🏷' => 'tag', '💎' => 'diamond', '🏆' => 'trophy', '🎟' => 'ticket', '❤' => 'heart', '💜' => 'heart',
		);
		$clean = preg_replace( '/[\x{FE0F}\x{20E3}]/u', '', $icon );
		if ( isset( $emoji_map[ $clean ] ) ) {
			$icon = $emoji_map[ $clean ];
		}
		return self::icon_markup( $icon );
	}

	private static function category_chips( array $content ): string {
		$lines = preg_split( '/\r\n|\r|\n/', (string) ( $content['items'] ?? '' ) );
		$items = array();
		foreach ( array_slice( (array) $lines, 0, 20 ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 4 ) );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			$icon  = sanitize_text_field( $parts[0] );
			$label = sanitize_text_field( $parts[1] );
			$url   = esc_url_raw( $parts[2] );
			$meta  = isset( $parts[3] ) ? sanitize_text_field( $parts[3] ) : '';
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$items[] = array(
				'icon'  => $icon,
				'label' => $label,
				'url'   => $url,
				'meta'  => $meta,
			);
		}

		if ( empty( $items ) ) {
			return '';
		}

		$style = 'grid' === ( $content['style'] ?? '' ) ? 'grid' : 'rail';
		$active_raw = (int) ( $content['active_index'] ?? 0 );
		$active = $active_raw < 0 ? -1 : max( 0, min( count( $items ) - 1, $active_raw ) );

		if ( 'grid' === $style ) {
			$out = '<nav class="delicat-category-chips delicat-category-chips--grid" aria-label="' . esc_attr__( 'Product categories', 'delicat-builder-v9' ) . '">';
			if ( ! empty( $content['eyebrow'] ) || ! empty( $content['title'] ) || ! empty( $content['subtitle'] ) ) {
				$out .= '<div class="delicat-category-chips__heading">';
				if ( ! empty( $content['eyebrow'] ) ) {
					$out .= '<span class="delicat-category-chips__eyebrow">' . esc_html( $content['eyebrow'] ) . '</span>';
				}
				if ( ! empty( $content['title'] ) ) {
					$out .= '<h2 class="delicat-category-chips__title">' . esc_html( $content['title'] ) . '</h2>';
				}
				if ( ! empty( $content['subtitle'] ) ) {
					$out .= '<p class="delicat-category-chips__subtitle">' . esc_html( $content['subtitle'] ) . '</p>';
				}
				$out .= '</div>';
			}
			$out .= '<div class="delicat-category-chips__grid">';
			foreach ( $items as $item ) {
				$out .= '<a class="delicat-category-tile" href="' . esc_url( $item['url'] ) . '" data-delicat-prefetch>';
				$out .= '<span class="delicat-category-tile__icon" aria-hidden="true">' . self::chip_icon( $item['icon'] ) . '</span>';
				$out .= '<span class="delicat-category-tile__copy"><strong>' . esc_html( $item['label'] ) . '</strong>';
				if ( '' !== $item['meta'] ) {
					$out .= '<small>' . esc_html( $item['meta'] ) . '</small>';
				}
				$out .= '</span><span class="delicat-category-tile__arrow" aria-hidden="true">→</span></a>';
			}
			$out .= '</div></nav>';
			return $out;
		}

		$out = '<nav class="delicat-category-chips delicat-category-chips--rail" aria-label="' . esc_attr__( 'Product categories', 'delicat-builder-v9' ) . '"><div class="delicat-category-chips__track">';
		foreach ( $items as $index => $item ) {
			$out .= '<a class="delicat-category-chip' . ( $index === $active ? ' is-active' : '' ) . '" href="' . esc_url( $item['url'] ) . '" data-delicat-prefetch>';
			if ( '' !== $item['icon'] ) {
				$out .= '<span class="delicat-category-chip__icon" aria-hidden="true">' . self::chip_icon( $item['icon'] ) . '</span>';
			}
			$out .= '<span>' . esc_html( $item['label'] ) . '</span></a>';
		}
		$out .= '</div></nav>';
		return $out;
	}

	private static function spacer( array $content ): string {
		$style = sprintf(
			'--dbv9-spacer-d:%dpx;--dbv9-spacer-t:%dpx;--dbv9-spacer-m:%dpx',
			absint( $content['height_desktop'] ),
			absint( $content['height_tablet'] ),
			absint( $content['height_mobile'] )
		);
		return '<div class="delicat-builder-spacer" style="' . esc_attr( $style ) . '" aria-hidden="true"></div>';
	}

}