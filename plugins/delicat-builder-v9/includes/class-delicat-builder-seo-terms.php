<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 9.10.0 — what Google reads for a product category and for the products in it.
 *
 * Customers search « top up free fire haiti », « top up ff haiti », « recharge diamants free fire
 * haïti ». On the live site (6 November 2026) no page carried those words in its title: product
 * titles read « Free Fire (LATAM) Delicat Store – Delicat Store » and there was no Free Fire page
 * grouping the regions. The category edit screen (Produits → Catégories → Modifier) now has:
 *  - « Titre Google » and « Description Google » for the category page itself;
 *  - « Titre Google de ses produits »: a pattern with {produit}, e.g. « Top Up {produit} Haïti –
 *    Diamants FF », given to every product of the category (current and future, any supplier) that
 *    has no title of its own in its Delicat SEO box;
 *  - « Description Google de ses produits »: the same for the description.
 * Only the text shown to Google changes; product names, categories and prices are not touched.
 */
final class Delicat_Builder_V9_SEO_Terms {

	const META_TITLE         = '_dlc_seo_title';
	const META_DESC          = '_dlc_seo_description';
	const META_PRODUCT_TITLE = '_dlc_seo_product_title';
	const META_PRODUCT_DESC  = '_dlc_seo_product_description';
	const TOKEN              = '{produit}';

	public static function boot(): void {
		if ( is_admin() ) {
			/* the edit screen only: a new category is created over AJAX, where this module is not loaded */
			add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'fields' ), 20 );
			add_action( 'edited_product_cat', array( __CLASS__, 'save' ), 10, 1 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	public static function term_title( WP_Term $term ): string {
		return trim( (string) get_term_meta( $term->term_id, self::META_TITLE, true ) );
	}

	public static function term_description( WP_Term $term ): string {
		return trim( (string) get_term_meta( $term->term_id, self::META_DESC, true ) );
	}

	/** The pattern from the product's main category (or its parents, then its other categories), with its name in place. */
	public static function product_title( WC_Product $product ): string {
		return self::from_pattern( $product, self::META_PRODUCT_TITLE );
	}

	public static function product_description( WC_Product $product ): string {
		return self::from_pattern( $product, self::META_PRODUCT_DESC );
	}

	private static function from_pattern( WC_Product $product, string $key ): string {
		$pattern = '';
		foreach ( self::category_chain( $product ) as $term_id ) {
			$pattern = trim( (string) get_term_meta( $term_id, $key, true ) );
			if ( '' !== $pattern ) {
				break;
			}
		}
		if ( '' === $pattern || false === strpos( $pattern, self::TOKEN ) ) {
			return '';
		}
		$name = trim( html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( self::TOKEN, $name, $pattern ) ) );
	}

	/** @return int[] main category, its parents, then the other categories and their parents. */
	private static function category_chain( WC_Product $product ): array {
		$chain   = array();
		$primary = class_exists( 'Delicat_Builder_V9_SEO', false ) ? Delicat_Builder_V9_SEO::primary_category( $product ) : null;
		$terms   = get_the_terms( $product->get_id(), 'product_cat' );
		$terms   = is_array( $terms ) ? $terms : array();
		if ( $primary instanceof WP_Term ) {
			array_unshift( $terms, $primary );
		}
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			foreach ( array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) ) as $id ) {
				$chain[ $id ] = $id;
			}
		}
		return array_values( $chain );
	}

	/* ------------------------------------------------------------------ */
	/* Category edit screen                                                */
	/* ------------------------------------------------------------------ */

	public static function fields( $term ): void {
		if ( ! $term instanceof WP_Term ) {
			return;
		}
		wp_nonce_field( 'delicat_seo_term_' . $term->term_id, 'delicat_seo_term_nonce' );
		$row = static function ( string $id, string $label, string $control, string $help ): void {
			echo '<tr class="form-field"><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>' . $control . '<p class="description">' . esc_html( $help ) . '</p></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controls are escaped where built.
		};
		echo '<tr class="form-field"><th scope="row" colspan="2"><h2 style="margin:12px 0 0">Delicat SEO — ce que Google montre</h2></th></tr>';
		$row( 'delicat-seo-term-title', 'Titre Google', '<input id="delicat-seo-term-title" type="text" maxlength="70" name="delicat_seo_term[title]" value="' . esc_attr( self::term_title( $term ) ) . '" placeholder="' . esc_attr( 'Top Up ' . html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' Haïti – livraison rapide | Delicat Store' ) . '">', 'Titre de cette page de catégorie dans Google, utilisé tel quel (environ 60 caractères). Vide : nom de la catégorie.' );
		$row( 'delicat-seo-term-desc', 'Description Google', '<textarea id="delicat-seo-term-desc" rows="3" maxlength="300" name="delicat_seo_term[description]">' . esc_textarea( self::term_description( $term ) ) . '</textarea>', 'Texte sous le titre dans Google (140 à 160 caractères). Vide : la description de la catégorie.' );
		$row( 'delicat-seo-term-ptitle', 'Titre Google de ses produits', '<input id="delicat-seo-term-ptitle" type="text" maxlength="90" name="delicat_seo_term[product_title]" value="' . esc_attr( (string) get_term_meta( $term->term_id, self::META_PRODUCT_TITLE, true ) ) . '" placeholder="Top Up {produit} Haïti – Diamants FF">', 'Modèle pour chaque produit de cette catégorie (et de ses sous-catégories) : {produit} est remplacé par son nom, « – Delicat Store » est ajouté. Un produit qui a son propre « Titre Google » le garde. Doit contenir {produit}.' );
		$row( 'delicat-seo-term-pdesc', 'Description Google de ses produits', '<textarea id="delicat-seo-term-pdesc" rows="3" maxlength="300" name="delicat_seo_term[product_description]" placeholder="Achetez {produit} en Haïti sur Delicat Store : paiement MonCash, NatCash ou portefeuille, livraison rapide.">' . esc_textarea( (string) get_term_meta( $term->term_id, self::META_PRODUCT_DESC, true ) ) . '</textarea>', 'Même principe pour la description. Doit contenir {produit}.' );
	}

	public static function save( $term_id ): void {
		$term_id = (int) $term_id;
		if ( ! isset( $_POST['delicat_seo_term_nonce'], $_POST['delicat_seo_term'] ) || ! is_array( $_POST['delicat_seo_term'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['delicat_seo_term_nonce'] ) ), 'delicat_seo_term_' . $term_id ) || ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}
		$in    = wp_unslash( $_POST['delicat_seo_term'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
		$clean = array(
			self::META_TITLE         => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			self::META_DESC          => sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ),
			self::META_PRODUCT_TITLE => sanitize_text_field( (string) ( $in['product_title'] ?? '' ) ),
			self::META_PRODUCT_DESC  => sanitize_textarea_field( (string) ( $in['product_description'] ?? '' ) ),
		);
		foreach ( array( self::META_PRODUCT_TITLE, self::META_PRODUCT_DESC ) as $key ) {
			if ( '' !== $clean[ $key ] && false === strpos( $clean[ $key ], self::TOKEN ) ) {
				$clean[ $key ] = ''; // without {produit} every product would get the same title
			}
		}
		$changed = false;
		foreach ( $clean as $key => $value ) {
			if ( (string) get_term_meta( $term_id, $key, true ) === $value ) {
				continue;
			}
			$changed = true;
			'' !== $value ? update_term_meta( $term_id, $key, $value ) : delete_term_meta( $term_id, $key );
		}
		if ( $changed ) {
			/* the titles of every product in the category change inside cached pages */
			if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
				Delicat_Builder_V9_Cache::bump_version();
			}
			do_action( 'litespeed_purge_all' );
		}
	}
}
