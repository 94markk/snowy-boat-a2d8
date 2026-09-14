<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Compat\V9Options;
use Delicat\V10\Context;
use Delicat\V10\Module;
use Delicat\V10\Nav\ViewTransitions;
use Delicat\V10\Shell\Icons;

defined( 'ABSPATH' ) || exit;

/**
 * The homepage.
 *
 * Renders the sections the merchant has already arranged - V10 reads V9's saved
 * layout rather than asking anyone to rebuild it - and draws each one from the
 * same component vocabulary as the rest of the store.
 *
 * -----------------------------------------------------------------------------
 * One renderer, not fourteen
 * -----------------------------------------------------------------------------
 * V9 had a 93KB renderer with a fourteen-branch switch, and each branch emitted
 * its own markup, its own inline custom properties and its own sizes. That is
 * why its homepage had five different card styles and why a fix to one section
 * never reached the others.
 *
 * Here every section is a heading plus a body, the body is one of a small number
 * of shapes, and the shapes are shared: the product rail on the homepage is the
 * same rail as on a category page, and a change to it changes both.
 */
final class Home extends Module {

	public static function routes(): array {
		return array( Context::ROUTE_HOME );
	}

	public function register(): void {
		/*
		 * `the_content` rather than a template override: a merchant who has
		 * built their front page in the block editor keeps it, and V10's
		 * sections are appended to what they wrote. Replacing the template
		 * would silently discard the page they can see in the editor.
		 */
		add_filter( 'the_content', array( $this, 'append_sections' ), 20 );
	}

	/** @param string $content */
	public function append_sections( $content ): string {
		if ( ! is_main_query() || ! in_the_loop() ) {
			return (string) $content;
		}

		$sections = self::sections();
		if ( array() === $sections ) {
			return (string) $content;
		}

		$out = '<div class="dlx-shell-width">';
		foreach ( $sections as $section ) {
			$out .= self::render( $section );
		}
		$out .= '</div>';

		return (string) $content . $out;
	}

	/**
	 * The merchant's layout, or a sensible default store.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections(): array {
		$sections = V9Options::sections();

		if ( array() === $sections ) {
			$sections = self::default_layout();
		}

		/** @param array<int,array<string,mixed>> $sections */
		$sections = apply_filters( 'delicat_v10_home_sections', $sections );

		return is_array( $sections ) ? $sections : array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function default_layout(): array {
		return array(
			array( 'type' => 'category_chips' ),
			array( 'type' => 'products', 'title' => __( 'Populaires', 'delicat-v10' ), 'layout' => 'rail', 'limit' => 10 ),
			array( 'type' => 'how_it_works' ),
			array( 'type' => 'products', 'title' => __( 'Nouveautés', 'delicat-v10' ), 'layout' => 'grid', 'orderby' => 'date', 'limit' => 8 ),
			array( 'type' => 'trust_strip' ),
		);
	}

	/**
	 * @param array<string,mixed> $section
	 */
	private static function render( array $section ): string {
		$type = sanitize_key( (string) ( $section['type'] ?? '' ) );

		if ( '' === $type || ! empty( $section['hidden'] ) ) {
			return '';
		}

		$body = '';

		switch ( $type ) {
			case 'products':
			case 'favorites':
				$body = self::products( $section );
				break;
			case 'category_chips':
				$body = self::chips( $section );
				break;
			case 'how_it_works':
				$body = self::steps( $section );
				break;
			case 'trust_strip':
				$body = self::trust( $section );
				break;
			case 'faq':
				$body = self::faq( $section );
				break;
			case 'text':
				$body = '<div class="dlx-prose">' . wp_kses_post( (string) ( $section['body'] ?? '' ) ) . '</div>';
				break;
			case 'spacer':
				return '<div style="height:var(--dlx-space-xl)" aria-hidden="true"></div>';
			default:
				/*
				 * An unknown section type renders nothing rather than a broken
				 * one. V9's renderer fell through to a generic branch that
				 * emitted an empty styled container, so a section type removed
				 * in an update left a visible gap on the homepage.
				 */
				return '';
		}

		if ( '' === $body ) {
			return '';
		}

		$title = trim( (string) ( $section['title'] ?? '' ) );
		$more  = trim( (string) ( $section['more_url'] ?? '' ) );

		$head = '';
		if ( '' !== $title ) {
			$head  = '<div class="dlx-section__head">';
			$head .= '<h2 class="dlx-section__title">' . esc_html( $title ) . '</h2>';
			if ( '' !== $more ) {
				$head .= '<a class="dlx-section__more" href="' . esc_url( $more ) . '">'
					. esc_html__( 'Voir tout', 'delicat-v10' ) . '</a>';
			}
			$head .= '</div>';
		}

		return '<section class="dlx-section">' . $head . $body . '</section>';
	}

	/* ---------------------------------------------------------------------
	 * Section bodies
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed> $section
	 */
	private static function products( array $section ): string {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return '';
		}

		$limit  = max( 1, min( 24, (int) ( $section['limit'] ?? 10 ) ) );
		$layout = 'grid' === ( $section['layout'] ?? 'rail' ) ? 'grid' : 'rail';

		$args = array(
			'limit'   => $limit,
			'status'  => 'publish',
			'orderby' => in_array( $section['orderby'] ?? '', array( 'date', 'popularity', 'rating', 'price' ), true )
				? (string) $section['orderby']
				: 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		if ( ! empty( $section['category'] ) ) {
			$args['category'] = array( sanitize_title( (string) $section['category'] ) );
		}
		if ( 'favorites' === ( $section['type'] ?? '' ) ) {
			$args['featured'] = true;
		}

		try {
			$products = wc_get_products( $args );
		} catch ( \Throwable $error ) {
			unset( $error );
			return '';
		}

		$cards = '';
		foreach ( (array) $products as $product ) {
			if ( ! $product instanceof \WC_Product || ! $product->is_visible() ) {
				continue;
			}
			$cards .= self::card( $product );
		}

		if ( '' === $cards ) {
			return '';
		}

		return '<div class="' . ( 'grid' === $layout ? 'dlx-grid' : 'dlx-rail' ) . '">' . $cards . '</div>';
	}

	/**
	 * One product card.
	 *
	 * The image carries the view-transition-name that the product page's hero
	 * also carries, so tapping this card animates it into the product page's
	 * image rather than crossfading the whole screen. The two sides arrive at
	 * the same name independently, from the product id, so neither needs to
	 * know about the other.
	 */
	private static function card( \WC_Product $product ): string {
		$id    = (int) $product->get_id();
		$name  = (string) $product->get_name();
		$url   = (string) get_permalink( $id );
		$image = (int) $product->get_image_id();

		$media = '';
		if ( $image > 0 ) {
			$media = (string) wp_get_attachment_image(
				$image,
				'woocommerce_thumbnail',
				false,
				array(
					'loading'  => 'lazy',
					'decoding' => 'async',
					'alt'      => '',
					'style'    => 'view-transition-name:' . ViewTransitions::product_name( $id ),
				)
			);
		}

		$badge = '';
		if ( $product->is_on_sale() ) {
			$badge = '<span class="dlx-product__badge">' . esc_html__( 'Promo', 'delicat-v10' ) . '</span>';
		}

		return sprintf(
			'<a class="dlx-product" href="%1$s"><span class="dlx-product__media">%2$s%3$s</span>'
			. '<span class="dlx-product__body"><span class="dlx-product__name">%4$s</span>'
			. '<span class="dlx-product__price">%5$s</span></span></a>',
			esc_url( $url ),
			$badge,
			$media,
			esc_html( $name ),
			wp_kses_post( $product->get_price_html() )
		);
	}

	/**
	 * @param array<string,mixed> $section
	 */
	private static function chips( array $section ): string {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => max( 2, min( 16, (int) ( $section['limit'] ?? 10 ) ) ),
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);

		if ( is_wp_error( $terms ) || array() === (array) $terms ) {
			return '';
		}

		$out = '<div class="dlx-chips">';
		$out .= sprintf(
			'<a class="dlx-chip" href="%s">%s</a>',
			esc_url( (string) wc_get_page_permalink( 'shop' ) ),
			esc_html__( 'Tous', 'delicat-v10' )
		);

		foreach ( (array) $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$out .= sprintf(
				'<a class="dlx-chip" href="%s">%s</a>',
				esc_url( (string) $link ),
				esc_html( $term->name )
			);
		}

		return $out . '</div>';
	}

	/**
	 * @param array<string,mixed> $section
	 */
	private static function steps( array $section ): string {
		$steps = isset( $section['items'] ) && is_array( $section['items'] ) && array() !== $section['items']
			? $section['items']
			: array(
				array( 'icon' => 'tag', 'title' => __( 'Choisissez votre recharge', 'delicat-v10' ), 'body' => __( 'Sélectionnez le jeu ou le service et le montant.', 'delicat-v10' ) ),
				array( 'icon' => 'wallet', 'title' => __( 'Payez en toute sécurité', 'delicat-v10' ), 'body' => __( 'Portefeuille, carte ou mobile money, au choix.', 'delicat-v10' ) ),
				array( 'icon' => 'gift', 'title' => __( 'Recevez instantanément', 'delicat-v10' ), 'body' => __( 'Votre solde est crédité dès la confirmation.', 'delicat-v10' ) ),
			);

		$out = '<div class="dlx-steps">';
		$n   = 0;

		foreach ( (array) $steps as $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$n++;
			$icon = sanitize_key( (string) ( $step['icon'] ?? '' ) );

			$out .= '<div class="dlx-step">';
			$out .= '<span class="dlx-step__n" aria-hidden="true">' . (int) $n . '</span>';
			if ( Icons::has( $icon ) ) {
				$out .= '<span class="dlx-step__icon">' . Icons::svg( $icon, 22 ) . '</span>';
			}
			$out .= '<h3>' . esc_html( (string) ( $step['title'] ?? '' ) ) . '</h3>';
			$out .= '<p>' . esc_html( (string) ( $step['body'] ?? '' ) ) . '</p>';
			$out .= '</div>';
		}

		return $out . '</div>';
	}

	/**
	 * @param array<string,mixed> $section
	 */
	private static function trust( array $section ): string {
		$items = isset( $section['items'] ) && is_array( $section['items'] ) && array() !== $section['items']
			? $section['items']
			: array(
				array( 'icon' => 'wallet', 'label' => __( 'Paiement sécurisé', 'delicat-v10' ) ),
				array( 'icon' => 'gift', 'label' => __( 'Livraison instantanée', 'delicat-v10' ) ),
				array( 'icon' => 'support', 'label' => __( 'Support 7j/7', 'delicat-v10' ) ),
			);

		$out = '<div class="dlx-trust">';

		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$icon  = sanitize_key( (string) ( $item['icon'] ?? '' ) );
			$label = trim( (string) ( $item['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$out .= '<span class="dlx-trust__item">'
				. ( Icons::has( $icon ) ? Icons::svg( $icon, 20 ) : '' )
				. '<span>' . esc_html( $label ) . '</span></span>';
		}

		return $out . '</div>';
	}

	/**
	 * FAQ as real <details> elements.
	 *
	 * Open, close, keyboard operation and find-in-page all come from the
	 * browser, so this section works with JavaScript unavailable and is
	 * searchable by the browser's own find. V9's accordion was a div with a
	 * click handler and neither was true of it.
	 *
	 * @param array<string,mixed> $section
	 */
	private static function faq( array $section ): string {
		$items = isset( $section['items'] ) && is_array( $section['items'] ) ? $section['items'] : array();
		if ( array() === $items ) {
			return '';
		}

		$out = '<div class="dlx-faq">';

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = trim( (string) ( $item['q'] ?? $item['title'] ?? '' ) );
			$a = trim( (string) ( $item['a'] ?? $item['body'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$out .= '<details class="dlx-faq__item"><summary>' . esc_html( $q ) . '</summary>'
				. '<div class="dlx-faq__body">' . wp_kses_post( $a ) . '</div></details>';
		}

		return $out . '</div>';
	}
}
