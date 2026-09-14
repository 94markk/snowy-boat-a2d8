<?php
/**
 * The homepage is a fixed sequence of sections, each switchable in the
 * Customizer, each a template part under template-parts/home/. A section
 * with nothing to show (an empty category, no reviews) renders nothing.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render the whole homepage body.
 */
function ds_home_render(): void {
	get_template_part( 'template-parts/home/hero' );

	if ( (int) ds_opt( 'show_chips' ) === 1 ) {
		get_template_part( 'template-parts/home/chips', null, array( 'title' => 'Catégories' ) );
	}
	if ( (int) ds_opt( 'show_trust' ) === 1 ) {
		get_template_part( 'template-parts/home/trust' );
	}
	if ( (int) ds_opt( 'show_best' ) === 1 ) {
		ds_home_rail(
			array(
				'eyebrow'  => 'CLASSEMENT',
				'title'    => (string) ds_opt( 'best_title' ),
				'subtitle' => (string) ds_opt( 'best_subtitle' ),
				'products' => ds_products( array( 'orderby' => 'popularity', 'limit' => 10 ) ),
				'more'     => ds_shop_url(),
				'layout'   => 'rail',
			)
		);
	}
	for ( $i = 1; $i <= 4; $i++ ) {
		$slug = (string) ds_opt( "rail_{$i}_cat" );
		if ( '' === $slug ) {
			continue;
		}
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( ! $term instanceof WP_Term ) {
			continue;
		}
		$products = ds_products( array( 'category' => $slug, 'orderby' => 'popularity', 'limit' => 10 ) );
		ds_home_rail(
			array(
				'eyebrow'  => 'CATÉGORIE',
				'title'    => (string) ds_opt( "rail_{$i}_title" ) ?: $term->name,
				'subtitle' => (string) ds_opt( "rail_{$i}_subtitle" ),
				'products' => $products,
				'more'     => (string) get_term_link( $term ),
				'layout'   => 'rail',
			)
		);
		if ( 2 === $i && (int) ds_opt( 'show_steps' ) === 1 ) {
			get_template_part( 'template-parts/home/steps' );
		}
	}
	if ( (int) ds_opt( 'show_promo' ) === 1 ) {
		ds_home_rail(
			array(
				'eyebrow'  => 'OFFRES',
				'title'    => 'Promos du moment',
				'subtitle' => 'Des prix réduits, pour un temps limité.',
				'products' => ds_products( array( 'on_sale' => true, 'orderby' => 'popularity', 'limit' => 8 ) ),
				'more'     => '',
				'layout'   => 'grid',
			)
		);
	}
	if ( (int) ds_opt( 'show_why' ) === 1 ) {
		get_template_part( 'template-parts/home/why' );
	}
	if ( (int) ds_opt( 'show_kliyan' ) === 1 ) {
		get_template_part( 'template-parts/home/kliyan' );
	}
	if ( (int) ds_opt( 'show_reviews' ) === 1 ) {
		get_template_part( 'template-parts/home/reviews', null, array( 'reviews' => ds_home_reviews() ) );
	}
	if ( (int) ds_opt( 'show_faq' ) === 1 ) {
		get_template_part( 'template-parts/home/faq' );
	}
	if ( (int) ds_opt( 'show_cta' ) === 1 ) {
		get_template_part( 'template-parts/home/cta' );
	}
}

/**
 * A titled product rail/grid; silent when empty.
 *
 * @param array{eyebrow:string,title:string,subtitle:string,products:WC_Product[],more:string,layout:string} $args Args.
 */
function ds_home_rail( array $args ): void {
	if ( array() === $args['products'] ) {
		return;
	}
	get_template_part( 'template-parts/home/rail', null, $args );
}

/**
 * Approved 5-star product reviews, else the Customizer's fallback quotes.
 *
 * @return array<int,array{name:string,text:string,product:string,rating:int}>
 */
function ds_home_reviews(): array {
	$comments = get_comments(
		array(
			'post_type'  => 'product',
			'status'     => 'approve',
			'type'       => 'review',
			'number'     => 8,
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'rating',
					'value'   => 4,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	$out = array();
	foreach ( (array) $comments as $comment ) {
		if ( ! $comment instanceof WP_Comment ) {
			continue;
		}
		$text = trim( wp_strip_all_tags( $comment->comment_content ) );
		if ( '' === $text ) {
			continue;
		}
		$out[] = array(
			'name'    => ds_mask_name( (string) $comment->comment_author ),
			'text'    => mb_substr( $text, 0, 220, 'UTF-8' ) . ( mb_strlen( $text, 'UTF-8' ) > 220 ? '…' : '' ),
			'product' => get_the_title( (int) $comment->comment_post_ID ),
			'rating'  => (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true ),
		);
	}
	if ( count( $out ) >= 3 ) {
		return $out;
	}
	for ( $i = 1; $i <= 3; $i++ ) {
		$text = trim( (string) ds_opt( "review_{$i}_text" ) );
		if ( '' === $text ) {
			continue;
		}
		$out[] = array(
			'name'    => (string) ds_opt( "review_{$i}_name" ),
			'text'    => $text,
			'product' => '',
			'rating'  => 5,
		);
	}
	return $out;
}
