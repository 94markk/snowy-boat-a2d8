<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 9.9.0 (SEO-02, SEO-03, SEO-05) — what the store proposes to Google.
 *
 * The live audit of 6 November 2026 found the core sitemap offering pages that
 * should never be search results (log-in and password forms, « test », the
 * maintenance notice, WordPress's « Hello world! » sample, empty pages), the
 * supplier's technical categories (« Delicat Digital › delicat-topup-c9e2614d39 »,
 * one per product, each named exactly like its product and so competing with
 * it), the « Uncategorized » defaults and every product tag (including an emoji
 * copy of « jeux »). Two pages also print the same rewards screen.
 *
 * This class keeps one list of hidden pages and one of hidden categories. Until
 * the owner saves the lists on the Delicat SEO screen, a recommendation is used
 * (computed from slugs, emptiness and the supplier's naming, cached). Hidden
 * means: robots noindex,follow and left out of the sitemap — the page itself
 * still opens normally, links keep working and nothing is deleted.
 * Breadcrumbs never pass through a hidden category.
 */
final class Delicat_Builder_V9_SEO_Index {

	const RECO_CACHE = 'dlc_seo_reco_v1';
	const META_CANONICAL = '_dlc_seo_canonical';

	/** Pages that are tools, forms, notices or tests rather than search results. */
	const UTILITY_SLUGS = array(
		'login', 'connexion', 'registration-page', 'inscription', 'forget-password', 'lost-password', 'mot-de-passe-oublie',
		'maintenance', 'test', 'test-2', 'sample-page', 'accueil', 'mes-donnees', 'delete-account', 'free-fire-redeem',
		'sondaj', 'adm-free-fire',
	);

	/** A page that prints the same screen as another: the second is the original. */
	const DUPLICATES = array( 'rewards-program' => 'program-bon-kliyan' );

	/** Supplier-created categories: one per product, named like the product. */
	const SUPPLIER_PARENT  = 'delicat-digital';
	const SUPPLIER_PATTERN = '/^delicat-(topup|giftcard)-[0-9a-f]{6,}$/';

	private static ?array $reco = null;

	/* ------------------------------------------------------------------ */
	/* The lists                                                           */
	/* ------------------------------------------------------------------ */

	/** @return int[] pages and posts not proposed to Google. */
	public static function hidden_post_ids(): array {
		$saved = Delicat_Builder_V9_SEO::settings()['hidden_posts'] ?? null;
		return is_array( $saved ) ? array_values( array_filter( array_map( 'absint', $saved ) ) ) : self::recommended()['posts'];
	}

	/** @return int[] product and blog categories not proposed to Google. */
	public static function hidden_term_ids(): array {
		$saved = Delicat_Builder_V9_SEO::settings()['hidden_terms'] ?? null;
		return is_array( $saved ) ? array_values( array_filter( array_map( 'absint', $saved ) ) ) : self::recommended()['terms'];
	}

	public static function index_tags(): bool {
		return ! empty( Delicat_Builder_V9_SEO::settings()['index_tags'] );
	}

	/** Pages WordPress or WooCommerce need public and indexable as they are. */
	private static function protected_ids(): array {
		$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ), (int) get_option( 'wp_page_for_privacy_policy' ) );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'terms' ) as $page ) {
				$ids[] = (int) wc_get_page_id( $page );
			}
		}
		if ( class_exists( 'Delicat_Builder_V9_Legal', false ) ) {
			foreach ( array_keys( Delicat_Builder_V9_Legal::documents() ) as $key ) {
				$ids[] = Delicat_Builder_V9_Legal::page_id( $key );
			}
		}
		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/**
	 * The recommendation, cached 12 hours and refreshed whenever a page, a post or
	 * a category changes.
	 *
	 * @return array{posts:int[],terms:int[]}
	 */
	public static function recommended(): array {
		if ( null !== self::$reco ) {
			return self::$reco;
		}
		$cached = get_transient( self::RECO_CACHE );
		if ( is_array( $cached ) && isset( $cached['posts'], $cached['terms'] ) ) {
			return self::$reco = $cached;
		}
		$protected = self::protected_ids();
		$posts     = array();
		foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 300, 'no_found_rows' => true, 'update_post_term_cache' => false ) ) as $post ) {
			if ( ! $post instanceof WP_Post || in_array( (int) $post->ID, $protected, true ) ) {
				continue;
			}
			if ( self::looks_hidden( $post ) ) {
				$posts[] = (int) $post->ID;
			}
		}
		$terms = array();
		$parent = get_term_by( 'slug', self::SUPPLIER_PARENT, 'product_cat' );
		if ( $parent instanceof WP_Term ) {
			$terms[] = (int) $parent->term_id;
			foreach ( (array) get_term_children( (int) $parent->term_id, 'product_cat' ) as $child ) {
				$terms[] = (int) $child;
			}
		}
		foreach ( (array) get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'id=>slug' ) ) as $id => $slug ) {
			if ( preg_match( self::SUPPLIER_PATTERN, (string) $slug ) ) {
				$terms[] = (int) $id;
			}
		}
		foreach ( array( 'product_cat' => 'default_product_cat', 'category' => 'default_category' ) as $taxonomy => $option ) {
			$default = get_term( (int) get_option( $option ), $taxonomy );
			if ( $default instanceof WP_Term && in_array( $default->slug, array( 'uncategorized', 'non-classe' ), true ) ) {
				$terms[] = (int) $default->term_id;
			}
		}
		self::$reco = array(
			'posts' => array_values( array_unique( $posts ) ),
			'terms' => array_values( array_unique( array_filter( $terms ) ) ),
		);
		set_transient( self::RECO_CACHE, self::$reco, 12 * HOUR_IN_SECONDS );
		return self::$reco;
	}

	/** Why a page is recommended hidden (empty string: it is not). */
	public static function hide_reason( WP_Post $post ): string {
		if ( 'post' === $post->post_type && 'hello-world' === $post->post_name && false !== stripos( (string) $post->post_content, 'WordPress' ) ) {
			return 'article d’exemple de WordPress';
		}
		if ( in_array( $post->post_name, self::UTILITY_SLUGS, true ) ) {
			return 'formulaire, outil, test ou avis technique';
		}
		if ( isset( self::DUPLICATES[ $post->post_name ] ) ) {
			return '';
		}
		if ( 'page' === $post->post_type && self::is_empty( $post ) ) {
			return 'page vide';
		}
		return '';
	}

	private static function looks_hidden( WP_Post $post ): bool {
		return '' !== self::hide_reason( $post );
	}

	/** No text, no Builder layout, no template that brings its own content. */
	private static function is_empty( WP_Post $post ): bool {
		if ( '' !== trim( html_entity_decode( wp_strip_all_tags( (string) $post->post_content ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
			return false;
		}
		$template = (string) get_page_template_slug( $post );
		if ( '' !== $template && 'default' !== $template ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Pages', false ) && ! empty( get_post_meta( $post->ID, Delicat_Builder_V9_Pages::META_ENABLED, true ) ) ) {
			$layout = get_post_meta( $post->ID, Delicat_Builder_V9_Pages::META_LAYOUT, true );
			if ( is_array( $layout ) && ! empty( $layout ) ) {
				return false;
			}
		}
		return true;
	}

	public static function flush(): void {
		self::$reco = null;
		delete_transient( self::RECO_CACHE );
	}

	public static function is_hidden_term( $term ): bool {
		return $term instanceof WP_Term && in_array( (int) $term->term_id, self::hidden_term_ids(), true );
	}

	/* ------------------------------------------------------------------ */
	/* Duplicates                                                          */
	/* ------------------------------------------------------------------ */

	/** The original's address for a page that duplicates another, or ''. */
	public static function original_url( WP_Post $post ): string {
		$typed = trim( (string) get_post_meta( $post->ID, self::META_CANONICAL, true ) );
		if ( '' !== $typed ) {
			return $typed;
		}
		if ( 'page' === $post->post_type && isset( self::DUPLICATES[ $post->post_name ] ) ) {
			$original = get_page_by_path( self::DUPLICATES[ $post->post_name ] );
			if ( $original instanceof WP_Post && 'publish' === $original->post_status && (int) $original->ID !== (int) $post->ID ) {
				return (string) get_permalink( $original );
			}
		}
		return '';
	}

	/** @return int[] pages pointing at another page as their original. */
	public static function duplicate_ids(): array {
		$ids = array();
		foreach ( array_keys( self::DUPLICATES ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post && '' !== self::original_url( $page ) ) {
				$ids[] = (int) $page->ID;
			}
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => self::META_CANONICAL, 'no_found_rows' => true ) ) as $id ) {
			$ids[] = (int) $id;
		}
		return array_values( array_unique( $ids ) );
	}

	/* ------------------------------------------------------------------ */
	/* Hooks                                                               */
	/* ------------------------------------------------------------------ */

	public static function boot(): void {
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'sitemap_taxonomies' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'sitemap_terms_args' ), 10, 2 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 10, 2 );
		add_filter( 'woocommerce_breadcrumb_main_term', array( __CLASS__, 'breadcrumb_main_term' ), 10, 2 );
		add_filter( 'woocommerce_get_breadcrumb', array( __CLASS__, 'breadcrumb_trail' ), 20 );
		add_filter( 'woocommerce_structured_data_breadcrumblist', array( __CLASS__, 'breadcrumb_markup' ), 20 );
		foreach ( array( 'save_post_page', 'save_post_post', 'deleted_post', 'created_product_cat', 'edited_product_cat', 'delete_product_cat', 'created_category', 'edited_category', 'delete_category' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush' ) );
		}
		add_action( 'update_option_' . Delicat_Builder_V9_SEO::OPTION, array( __CLASS__, 'settings_saved' ) );
	}

	/** Robots for the current request: true when it must not be indexed. */
	public static function hide_current(): bool {
		if ( is_singular( array( 'page', 'post' ) ) ) {
			return in_array( (int) get_queried_object_id(), self::hidden_post_ids(), true );
		}
		if ( is_category() || ( function_exists( 'is_product_category' ) && is_product_category() ) ) {
			return self::is_hidden_term( get_queried_object() );
		}
		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return ! self::index_tags();
		}
		return false;
	}

	/** @return int[] page and post ids the sitemap leaves out. */
	public static function sitemap_excluded_posts(): array {
		return array_values( array_unique( array_merge( self::hidden_post_ids(), self::duplicate_ids() ) ) );
	}

	public static function sitemap_taxonomies( $taxonomies ) {
		if ( is_array( $taxonomies ) && Delicat_Builder_V9_SEO::active() && ! self::index_tags() ) {
			unset( $taxonomies['product_tag'] );
		}
		return $taxonomies;
	}

	public static function sitemap_terms_args( $args, $taxonomy ) {
		if ( ! is_array( $args ) || ! Delicat_Builder_V9_SEO::active() || ! in_array( $taxonomy, array( 'product_cat', 'category' ), true ) ) {
			return $args;
		}
		$hidden = self::hidden_term_ids();
		if ( ! empty( $hidden ) ) {
			$args['exclude'] = array_values( array_unique( array_merge( array_map( 'absint', (array) ( $args['exclude'] ?? array() ) ), $hidden ) ) );
		}
		return $args;
	}

	public static function canonical( $url, $post ) {
		if ( ! $post instanceof WP_Post || ! Delicat_Builder_V9_SEO::active() ) {
			return $url;
		}
		$original = self::original_url( $post );
		return '' !== $original ? $original : $url;
	}

	/** The visible trail goes through the same category as the breadcrumb data. */
	public static function breadcrumb_main_term( $term, $terms ) {
		if ( ! Delicat_Builder_V9_SEO::active() ) {
			return $term;
		}
		$product = function_exists( 'wc_get_product' ) && is_singular( 'product' ) ? wc_get_product( get_queried_object_id() ) : null;
		$primary = $product instanceof WC_Product ? Delicat_Builder_V9_SEO::primary_category( $product ) : null;
		if ( $primary instanceof WP_Term ) {
			return $primary;
		}
		if ( ! self::is_hidden_term( $term ) ) {
			return $term;
		}
		foreach ( (array) $terms as $candidate ) {
			if ( $candidate instanceof WP_Term && ! self::is_hidden_term( $candidate ) ) {
				return $candidate;
			}
		}
		return $term;
	}

	/** WooCommerce's own trail (visible breadcrumb and its data) without hidden categories. */
	public static function breadcrumb_trail( $crumbs ) {
		if ( ! is_array( $crumbs ) || ! Delicat_Builder_V9_SEO::active() ) {
			return $crumbs;
		}
		$hidden_links = array();
		foreach ( self::hidden_term_ids() as $id ) {
			$term = get_term( $id );
			$link = $term instanceof WP_Term ? get_term_link( $term ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				$hidden_links[ untrailingslashit( $link ) ] = true;
			}
		}
		if ( empty( $hidden_links ) || ( function_exists( 'is_product_category' ) && is_product_category() ) ) {
			return $crumbs;
		}
		return array_values(
			array_filter(
				$crumbs,
				static function ( $crumb ) use ( $hidden_links ): bool {
					return ! ( is_array( $crumb ) && isset( $crumb[1] ) && isset( $hidden_links[ untrailingslashit( (string) $crumb[1] ) ] ) );
				}
			)
		);
	}

	/**
	 * SEO-06: one author for breadcrumb data. WooCommerce adds its own BreadcrumbList when the
	 * visible trail is printed, and its JSON printer turns « Jeux & Recharges » into
	 * « Jeux &amp;amp; Recharges ». Its copy is dropped; Delicat SEO writes the trail (same
	 * category as the visible one, plain text) in its place.
	 */
	public static function breadcrumb_markup( $markup ) {
		return Delicat_Builder_V9_SEO::active() ? array() : $markup;
	}

	/** A new list changes robots tags inside cached pages: start them over. */
	public static function settings_saved(): void {
		self::flush();
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		do_action( 'litespeed_purge_all' );
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	public static function sanitize( array $input, array $current ): array {
		if ( empty( $input['hidden_ui'] ) ) {
			return array(
				'hidden_posts' => $current['hidden_posts'] ?? null,
				'hidden_terms' => $current['hidden_terms'] ?? null,
				'index_tags'   => empty( $current['index_tags'] ) ? 0 : 1,
			);
		}
		$ids = static function ( $list ): array {
			return array_values( array_unique( array_filter( array_map( 'absint', is_array( $list ) ? $list : array() ) ) ) );
		};
		return array(
			'hidden_posts' => $ids( $input['hidden_posts'] ?? array() ),
			'hidden_terms' => $ids( $input['hidden_terms'] ?? array() ),
			'index_tags'   => empty( $input['index_tags'] ) ? 0 : 1,
		);
	}

	public static function render_settings( string $opt ): void {
		$reco      = self::recommended();
		$hidden_p  = self::hidden_post_ids();
		$hidden_t  = self::hidden_term_ids();
		$protected = self::protected_ids();
		echo '<h2>Ce que Google voit</h2>';
		echo '<p>Les éléments cochés ne sont plus proposés à Google (balise <code>noindex</code>) et sortent du plan du site. Ils restent accessibles normalement : rien n’est supprimé. Tant que vous n’avez pas enregistré, la sélection recommandée s’applique.</p>';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[hidden_ui]" value="1">';
		echo '<table class="form-table" role="presentation"><tr><th scope="row">Pages et articles</th><td><fieldset style="columns:2 280px">';
		foreach ( get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) ) as $post ) {
			if ( in_array( (int) $post->ID, $protected, true ) ) {
				continue;
			}
			$why = in_array( (int) $post->ID, $reco['posts'], true ) ? self::hide_reason( $post ) : '';
			$dup = '' !== self::original_url( $post ) ? ' — double d’une autre page : Google est renvoyé vers l’originale' : '';
			echo '<label style="display:block;margin:0 0 6px;break-inside:avoid"><input type="checkbox" name="' . esc_attr( $opt ) . '[hidden_posts][]" value="' . esc_attr( (string) $post->ID ) . '" ' . checked( in_array( (int) $post->ID, $hidden_p, true ), true, false ) . '> ' . esc_html( get_the_title( $post ) ?: $post->post_name ) . ' <code>/' . esc_html( $post->post_name ) . '/</code>' . ( '' !== $why ? ' <em>recommandé : ' . esc_html( $why ) . '</em>' : '' ) . esc_html( $dup ) . '</label>';
		}
		echo '</fieldset></td></tr><tr><th scope="row">Catégories</th><td><fieldset style="columns:2 280px">';
		foreach ( array( 'product_cat' => 'produits', 'category' => 'articles' ) as $taxonomy => $label ) {
			foreach ( (array) get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ) ) as $term ) {
				if ( ! $term instanceof WP_Term ) {
					continue;
				}
				$why = in_array( (int) $term->term_id, $reco['terms'], true ) ? ' <em>recommandé</em>' : '';
				echo '<label style="display:block;margin:0 0 6px;break-inside:avoid"><input type="checkbox" name="' . esc_attr( $opt ) . '[hidden_terms][]" value="' . esc_attr( (string) $term->term_id ) . '" ' . checked( in_array( (int) $term->term_id, $hidden_t, true ), true, false ) . '> ' . esc_html( html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' <code>' . esc_html( $term->slug ) . '</code> (' . esc_html( $label . ', ' . (int) $term->count ) . ')' . $why . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $why is a fixed string.
			}
		}
		echo '</fieldset><p class="description">Recommandées : les catégories créées par le fournisseur (« Delicat Digital », une par produit, qui portent le même nom que le produit et lui font concurrence dans Google) et « Uncategorized ». Le fil d’Ariane ne passe jamais par une catégorie cachée.</p></td></tr>';
		echo '<tr><th scope="row">Étiquettes produits</th><td><label><input type="checkbox" name="' . esc_attr( $opt ) . '[index_tags]" value="1" ' . checked( self::index_tags(), true, false ) . '> Proposer les pages d’étiquettes (« jeux », « gift-card »…) à Google</label><p class="description">Décoché (recommandé) : ces pages répètent les catégories ; les étiquettes servent toujours aux badges des produits.</p></td></tr></table>';
	}
}
