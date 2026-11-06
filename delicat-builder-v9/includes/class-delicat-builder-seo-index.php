<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 9.9.0 (SEO-02, SEO-03, SEO-05) — which addresses the store offers, to Google and to visitors.
 *
 * The live audit of 6 November 2026 found the sitemap offering pages that should never be
 * search results and addresses that are not real store pages: log-in and password forms,
 * « test » pages, WordPress's « Hello world! », empty pages, the categories suppliers create
 * (Delicat Digital makes one per product under « delicat-digital », named like the product;
 * « Direct Topup » and « EU Direct Topup » are supplier names, not shelves), « Uncategorized »,
 * categories that only repeat their single product (« eFOOTBALL », « ONE STATE RP »), two
 * categories with the same name (« Réseaux sociaux » / « Reseaux Sociaux ») and a tag copy
 * with an emoji.
 *
 * Every page and category has one state:
 *  - show      offered to Google;
 *  - hide      still opens, but noindex,follow and out of the sitemap (log-in, maintenance…);
 *  - redirect  not a real page: visitors and Google are sent (301) to the real one — the
 *              product a category repeats, the category it copies, the shop or the home page —
 *              and it disappears from the sitemap, the shop's category chips and the menus.
 * The state comes from rules that work for any supplier (machine-made slugs, supplier names,
 * single-product copies, same-name duplicates). They are recomputed whenever a page, a category
 * or a product changes, so a category the next supplier sync creates is handled with no action.
 * The owner can override any item on the Delicat SEO screen; overrides are stored, everything
 * else follows the rules. Nothing is deleted: products keep their categories (reseller prices,
 * the assistant and the supplier sync read them) and people who edit pages open every address.
 */
final class Delicat_Builder_V9_SEO_Index {

	const CACHE          = 'dlc_seo_index_v2';
	const META_CANONICAL = '_dlc_seo_canonical';
	const SHOW           = 'show';
	const HIDE           = 'hide';
	const REDIRECT       = 'redirect';
	const STATES         = array( 'show', 'hide', 'redirect' );

	/** Top-level pages that work but are no search result: they stay open, out of Google. */
	const FUNCTIONAL_SLUGS = array( 'login', 'connexion', 'registration-page', 'inscription', 'forget-password', 'lost-password', 'mot-de-passe-oublie', 'maintenance', 'mes-donnees', 'delete-account', 'free-fire-redeem', 'adm-free-fire' );
	/** Top-level pages that are not real pages: sent to the home page. */
	const FAKE_SLUGS = array( 'test', 'test-2', 'test-3', 'sample-page', 'page-exemple', 'accueil' );
	/** Pages other plugins draw from their address with no content of their own: never « empty ». */
	const PLUGIN_SLUGS = array( 'my-wallet', 'wallet', 'woo-wallet', 'mon-portefeuille', 'portefeuille', 'application', 'support', 'tutoriels', 'sondaj', 'rewards-program', 'program-bon-kliyan', 'echanges', 'free-fire-pin' );
	/** A page that prints the same screen as another: the second is the original. */
	const DUPLICATES = array( 'rewards-program' => 'program-bon-kliyan' );
	/** Supplier categories by slug (filterable); any other supplier is caught by the rules below. */
	const SUPPLIER_SLUGS = array( 'delicat-digital', 'direct-topup', 'eu-direct-topup' );
	const SUPPLIER_NAME  = '/(direct[\s_-]*top[\s_-]*up|top[\s_-]*up[\s_-]*api|fournisseur|supplier)/i';
	/** A slug carrying a machine code (eight hexadecimal characters or more, one a digit): « delicat-topup-c9e2614d39 ». */
	const MACHINE_SLUG = '/(?:^|-)(?=[0-9a-f]*[0-9])[0-9a-f]{8,}(?:-|$)/';

	private static ?array $map       = null;
	private static ?array $protected = null;

	/* ------------------------------------------------------------------ */
	/* The map: the state of every page and category that is not « show »  */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array{posts:array<int,array{0:string,1:string}>,terms:array<int,array{0:string,1:string,2:string}>,paths:array<string,string>,supplier_auto:int}
	 */
	public static function map(): array {
		if ( null !== self::$map ) {
			return self::$map;
		}
		/* the stamp the plugin's bootstrap renews on every change (delicat_builder_v9_seo_index_stale) */
		$gen    = function_exists( 'delicat_builder_v9_seo_index_generation' ) ? delicat_builder_v9_seo_index_generation() : '';
		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['posts'], $cached['terms'], $cached['paths'], $cached['targets'], $cached['supplier_auto'] ) && ( $cached['gen'] ?? null ) === $gen ) {
			return self::$map = $cached;
		}
		$reco      = self::recommend();
		$overrides = self::overrides();
		$protected = self::protected_ids();
		$posts     = array();
		foreach ( $reco['posts'] + array_fill_keys( array_keys( $overrides['post'] ), array( self::SHOW, '' ) ) as $id => $row ) {
			$state = $overrides['post'][ $id ] ?? $row[0];
			if ( self::SHOW !== $state && ! in_array( (int) $id, $protected, true ) && 'publish' === get_post_status( (int) $id ) ) {
				$posts[ (int) $id ] = array( $state, $row[1] );
			}
		}
		$terms = array();
		foreach ( $reco['terms'] + array_fill_keys( array_keys( $overrides['term'] ), array( self::SHOW, '', '' ) ) as $id => $row ) {
			$state = $overrides['term'][ $id ] ?? $row[0];
			if ( self::SHOW !== $state ) {
				$terms[ (int) $id ] = array( $state, $row[1], $row[2] ?? '' );
			}
		}
		/* The address of every hidden or redirected item (and where a redirected one leads), so menus, chips and
		 * breadcrumbs test a link without a query. */
		$paths   = array();
		$targets = array();
		foreach ( $posts as $id => $row ) {
			$path           = self::path( (string) get_permalink( $id ) );
			$paths[ $path ] = $row[0];
			if ( self::REDIRECT === $row[0] ) {
				$targets[ $path ] = home_url( '/' );
			}
		}
		if ( $terms ) {
			/* every redirected category that may lead to its one product (rules or the owner's choice) */
			$singles = self::single_products( array_keys( array_filter( $terms, static fn( $row ) => self::REDIRECT === $row[0] && ! in_array( (string) ( $row[2] ?? '' ), array( 'shop', 'home' ), true ) && 0 !== strpos( (string) ( $row[2] ?? '' ), 'keeper:' ) ) ) );
			$found   = get_terms( array( 'include' => array_keys( $terms ), 'hide_empty' => false, 'taxonomy' => array( 'product_cat', 'category', 'product_tag' ) ) );
			foreach ( is_array( $found ) ? $found : array() as $term ) {
				$link = $term instanceof WP_Term ? get_term_link( $term ) : '';
				if ( ! is_string( $link ) || '' === $link || ! isset( $terms[ (int) $term->term_id ] ) ) {
					continue;
				}
				$row            = $terms[ (int) $term->term_id ];
				$path           = self::path( $link );
				$paths[ $path ] = $row[0];
				if ( self::REDIRECT === $row[0] ) {
					$targets[ $path ] = self::resolve_target( $term, (string) $row[2], $terms, $singles );
				}
			}
		}
		unset( $paths[''], $paths['/'], $targets[''], $targets['/'] );
		self::$map = array( 'posts' => $posts, 'terms' => $terms, 'paths' => $paths, 'targets' => $targets, 'supplier_auto' => (int) $reco['supplier_auto'], 'gen' => $gen );
		set_transient( self::CACHE, self::$map, 12 * HOUR_IN_SECONDS );
		return self::$map;
	}

	/** « https://www.example.com/Shop/?x=1 », « /shop » → « /shop/ » (host, query and case ignored). */
	public static function path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === $path ? '' : strtolower( '/' . trim( rawurldecode( $path ), '/' ) . '/' );
	}

	/** @return array{post:array<int,string>,term:array<int,string>} the owner's choices. */
	public static function overrides(): array {
		return self::clean_overrides( Delicat_Builder_V9_SEO::settings()['seo_overrides'] ?? null );
	}

	private static function clean_overrides( $raw ): array {
		$out = array( 'post' => array(), 'term' => array() );
		foreach ( array( 'post', 'term' ) as $kind ) {
			$list = is_array( $raw ) && isset( $raw[ $kind ] ) && is_array( $raw[ $kind ] ) ? $raw[ $kind ] : array();
			foreach ( $list as $id => $state ) {
				if ( absint( $id ) > 0 && in_array( $state, self::STATES, true ) ) {
					$out[ $kind ][ absint( $id ) ] = $state;
				}
			}
		}
		return $out;
	}

	/** Pages that stay public and indexable whatever a rule or a choice says. */
	public static function protected_ids(): array {
		if ( null !== self::$protected ) {
			return self::$protected;
		}
		$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ), (int) get_option( 'wp_page_for_privacy_policy' ) );
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $page ) {
				$ids[] = (int) wc_get_page_id( $page );
			}
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Legal', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-legal.php' ); // not loaded in wp-admin
		}
		if ( class_exists( 'Delicat_Builder_V9_Legal', false ) ) {
			foreach ( array_keys( Delicat_Builder_V9_Legal::documents() ) as $key ) {
				$ids[] = Delicat_Builder_V9_Legal::page_id( (string) $key );
			}
		}
		$ids = (array) apply_filters( 'delicat_builder_v9_seo_protected_pages', $ids );
		return self::$protected = array_values( array_filter( array_unique( array_map( 'intval', $ids ) ), static fn( $id ) => $id > 0 ) );
	}

	/** Pages other plugins draw from their address or a saved setting (wallet, app download, referral, resellers). */
	private static function plugin_page_ids(): array {
		$ids = array( (int) get_option( 'delicat_builder_v9_android_page', 0 ) );
		if ( function_exists( 'delicat_ref_page_id' ) ) {
			$ids[] = (int) delicat_ref_page_id();
		}
		if ( function_exists( 'dlr_cfg' ) ) {
			$ids[] = (int) dlr_cfg( 'page_id' );
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name__in' => self::PLUGIN_SLUGS, 'numberposts' => count( self::PLUGIN_SLUGS ), 'fields' => 'ids', 'no_found_rows' => true ) ) as $id ) {
			$ids[] = (int) $id;
		}
		return array_merge( $ids, self::protected_ids() );
	}

	/**
	 * The rules. Pages: by slug (top-level pages only), the sample post, truly empty pages.
	 * Categories: machine-made slugs and supplier names, a parent of machine-made children,
	 * single-product copies, same-name duplicates, the « Uncategorized » defaults; tag copies.
	 *
	 * @return array{posts:array<int,array{0:string,1:string}>,terms:array<int,array{0:string,1:string,2:string}>,supplier_auto:int}
	 */
	public static function recommend(): array {
		$posts = array();
		$slugs = array_merge( self::FUNCTIONAL_SLUGS, self::FAKE_SLUGS );
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 0, 'post_name__in' => $slugs, 'numberposts' => count( $slugs ), 'no_found_rows' => true, 'update_post_term_cache' => false ) ) as $post ) {
			$posts[ (int) $post->ID ] = in_array( $post->post_name, self::FAKE_SLUGS, true )
				? array( self::REDIRECT, 'page de test, d’exemple ou modèle de thème' )
				: array( self::HIDE, 'formulaire ou outil : reste ouvert' );
		}
		foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'name' => 'hello-world', 'numberposts' => 1, 'no_found_rows' => true ) ) as $post ) {
			if ( false !== stripos( (string) $post->post_content, 'WordPress' ) ) {
				$posts[ (int) $post->ID ] = array( self::REDIRECT, 'article d’exemple de WordPress' );
			}
		}
		foreach ( self::empty_page_ids() as $id ) {
			$posts[ $id ] = $posts[ $id ] ?? array( self::REDIRECT, 'page vide' );
		}

		$terms   = array();
		$all     = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$by_id   = array();
		$machine = array();
		foreach ( is_array( $all ) ? $all : array() as $term ) {
			if ( $term instanceof WP_Term ) {
				$by_id[ (int) $term->term_id ] = $term;
				if ( preg_match( self::MACHINE_SLUG, $term->slug ) ) {
					$machine[ (int) $term->term_id ] = true;
				}
			}
		}
		/* WooCommerce rewrites category counts on the storefront (sub-categories included, visibility applied) but
		 * not in wp-admin: the rules read the stored direct counts so they give the same answer everywhere. */
		$count        = self::direct_counts( 'product_cat' );
		$machine_kids = array();
		$other_kids   = array();
		foreach ( $by_id as $id => $term ) {
			$parent = (int) $term->parent;
			if ( $parent > 0 && isset( $machine[ $id ] ) ) {
				$machine_kids[ $parent ] = ( $machine_kids[ $parent ] ?? 0 ) + 1;
			} elseif ( $parent > 0 ) {
				$other_kids[ $parent ] = true;
			}
		}
		/* a group of supplier categories: two machine-made children or more, no real sub-shelf and no product of its
		 * own (a real shelf where a supplier left categories keeps its address; a supplier whose parent also holds
		 * products is caught by its name, or the owner chooses « Redirigée ») */
		$supplier_group = static function ( int $id ) use ( $machine_kids, $other_kids, $count ): bool {
			return (int) ( $machine_kids[ $id ] ?? 0 ) >= 2 && empty( $other_kids[ $id ] ) && 0 === (int) ( $count[ $id ] ?? 0 );
		};
		$supplier_slugs = array_map( 'strval', (array) apply_filters( 'delicat_builder_v9_seo_supplier_categories', self::SUPPLIER_SLUGS ) );
		$supplier_auto  = 0;
		foreach ( $by_id as $id => $term ) {
			$name = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( isset( $machine[ $id ] ) ) {
				$terms[ $id ] = array( self::REDIRECT, 'catégorie créée par un fournisseur', 'product' );
				$supplier_auto++;
			} elseif ( in_array( $term->slug, $supplier_slugs, true ) || preg_match( self::SUPPLIER_NAME, $name . ' ' . $term->slug ) || $supplier_group( (int) $id ) ) {
				$terms[ $id ] = array( self::REDIRECT, 'nom d’un fournisseur, pas un rayon de la boutique', 'shop' );
			}
		}
		$default = (int) get_option( 'default_product_cat' );
		if ( isset( $by_id[ $default ] ) && in_array( $by_id[ $default ]->slug, array( 'uncategorized', 'non-classe' ), true ) ) {
			$terms[ $default ] = array( self::REDIRECT, 'catégorie par défaut de WooCommerce', 'shop' );
		}
		/* a category whose only product carries its name repeats that product (not a parent: its sub-shelves stay) */
		$candidates = array();
		foreach ( $by_id as $id => $term ) {
			if ( 1 === (int) ( $count[ $id ] ?? 0 ) && ! isset( $terms[ $id ] ) && empty( $other_kids[ $id ] ) && empty( $machine_kids[ $id ] ) ) {
				$candidates[] = (int) $id;
			}
		}
		$titles  = array();
		$singles = self::single_products( $candidates, false, $titles );
		foreach ( $singles as $id => $product_id ) {
			$term = $by_id[ $id ];
			if ( self::norm( (string) ( $titles[ $id ] ?? '' ) ) === self::norm( $term->name ) ) {
				$terms[ $id ] = array( self::REDIRECT, 'répète le seul produit qu’elle contient', 'product' );
			}
		}
		/* two categories (or two tags) with the same name: the busier, plainer one stays */
		$terms += self::same_name_duplicates( array_diff_key( $by_id, $terms ), $count );
		$tags   = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
		$terms += self::same_name_duplicates( is_array( $tags ) ? $tags : array(), self::direct_counts( 'product_tag' ) );
		$blog   = get_term( (int) get_option( 'default_category' ), 'category' );
		if ( $blog instanceof WP_Term && in_array( $blog->slug, array( 'uncategorized', 'non-classe' ), true ) ) {
			$terms[ (int) $blog->term_id ] = array( self::REDIRECT, 'catégorie d’articles par défaut', 'home' );
		}
		return array( 'posts' => $posts, 'terms' => $terms, 'supplier_auto' => $supplier_auto );
	}

	/** @return array<int,int> term id => products directly in it, as stored (one query). */
	private static function direct_counts( string $taxonomy ): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_id, count FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ) ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$out[ (int) $row->term_id ] = (int) $row->count;
		}
		return $out;
	}

	/**
	 * @param WP_Term[]       $terms
	 * @param array<int,int>  $count direct counts
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	private static function same_name_duplicates( array $terms, array $count ): array {
		$groups = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && '' !== self::norm( $term->name ) ) {
				$groups[ $term->taxonomy . '|' . (int) $term->parent . '|' . self::norm( $term->name ) ][] = $term; // « iTunes › USA » and « PSN › USA » are two shelves
			}
		}
		$out = array();
		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			usort(
				$group,
				static function ( WP_Term $a, WP_Term $b ) use ( $count ): int {
					return array( (int) ( $count[ (int) $b->term_id ] ?? 0 ), strlen( $a->slug ), (int) $a->term_id ) <=> array( (int) ( $count[ (int) $a->term_id ] ?? 0 ), strlen( $b->slug ), (int) $b->term_id );
				}
			);
			$keeper = array_shift( $group );
			foreach ( $group as $copy ) {
				$out[ (int) $copy->term_id ] = array( self::REDIRECT, 'double de « ' . html_entity_decode( $keeper->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . ' »', 'keeper:' . (int) $keeper->term_id );
			}
		}
		return $out;
	}

	/** Letters and digits only, no accents, no emoji: « 🎮 JEUX » = « jeux », « Réseaux sociaux » = « Reseaux Sociaux ». */
	private static function norm( string $text ): string {
		$text = remove_accents( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $text ) );
	}

	/**
	 * One query for many categories: term id => its product, for those holding exactly one published product
	 * directly. Only categories counted at 1 are asked.
	 *
	 * @param int[] $term_ids
	 * @return array<int,int>
	 */
	private static function single_products( array $term_ids, bool $prime = true, ?array &$titles = null ): array {
		global $wpdb;
		$term_ids = array_values( array_filter( array_map( 'intval', $term_ids ) ) );
		$titles   = array();
		if ( empty( $term_ids ) ) {
			return array();
		}
		$out = array();
		foreach ( array_chunk( $term_ids, 500 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = $wpdb->get_results( "SELECT tt.term_id, MIN(p.ID) AS pid, MIN(p.post_title) AS title, COUNT(*) AS n FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ({$in}) AND p.post_type = 'product' AND p.post_status = 'publish' GROUP BY tt.term_id HAVING n = 1" ); // phpcs:ignore WordPress.DB -- integers only.
			foreach ( (array) $rows as $row ) {
				$out[ (int) $row->term_id ]    = (int) $row->pid;
				$titles[ (int) $row->term_id ] = (string) $row->title;
			}
		}
		if ( $out && $prime ) {
			_prime_post_caches( array_values( $out ), false, false ); // their addresses are read next
		}
		return $out;
	}

	private static function single_product( int $term_id ): int {
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 2, 'fields' => 'ids', 'no_found_rows' => true, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term_id, 'include_children' => false ) ) ) );
		return 1 === count( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Published top-level pages with nothing to show: no text, no picture or embed, no shortcode,
	 * no block that renders by itself, no page-builder data, no Delicat Builder layout, no
	 * template, no child page, and not a page another plugin draws (wallet, app download…).
	 *
	 * @return int[]
	 */
	private static function empty_page_ids(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = 0 AND CHAR_LENGTH(post_content) < 600" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $rows ) ) {
			return array();
		}
		$keep = self::plugin_page_ids();
		$out  = array();
		foreach ( $rows as $row ) {
			$id      = (int) $row->ID;
			$content = (string) $row->post_content;
			/* nothing at all once comments, empty paragraphs, line breaks and spaces are gone: any element left (a div
			 * a script mounts on, a button, a canvas, an embed…) or any text or shortcode means the page shows something */
			$bare = (string) preg_replace( array( '/<!--.*?-->/s', '~</?p\b[^>]*>|<br\s*/?>|&nbsp;|&#160;~i' ), '', $content );
			if ( in_array( $id, $keep, true ) || '' !== trim( $bare, " \t\n\r\0\x0B\xC2\xA0" ) ) {
				continue;
			}
			if ( function_exists( 'parse_blocks' ) && self::has_real_block( parse_blocks( $content ) ) ) {
				continue; // a dynamic block (« latest posts », a product grid…) writes nothing into the content
			}
			$template = (string) get_page_template_slug( $id );
			if ( ( '' !== $template && 'default' !== $template ) || '' !== trim( (string) get_post_meta( $id, '_elementor_data', true ), " []\n\r\t" ) ) {
				continue;
			}
			if ( ! empty( get_post_meta( $id, '_delicat_builder_v9_layout', true ) ) || ! empty( get_post_meta( $id, '_delicat_builder_v9_enabled', true ) ) ) {
				continue;
			}
			if ( get_children( array( 'post_parent' => $id, 'post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids' ) ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/** Any block, at any depth, other than a paragraph or classic text. */
	private static function has_real_block( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && ! in_array( $block['blockName'], array( 'core/paragraph', 'core/freeform' ), true ) ) {
				return true;
			}
			if ( ! empty( $block['innerBlocks'] ) && self::has_real_block( (array) $block['innerBlocks'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** This request's copies only (the stored map is retired by its generation stamp). */
	public static function forget(): void {
		self::$map       = null;
		self::$protected = null;
	}

	public static function flush(): void {
		self::forget();
		delete_transient( self::CACHE );
	}

	/* ------------------------------------------------------------------ */
	/* Questions the rest of the plugin asks                               */
	/* ------------------------------------------------------------------ */

	public static function post_state( int $id ): string {
		if ( $id <= 0 || in_array( $id, self::protected_ids(), true ) ) {
			return self::SHOW;
		}
		return self::map()['posts'][ $id ][0] ?? self::SHOW;
	}

	public static function term_state( int $id ): string {
		return self::map()['terms'][ $id ][0] ?? self::SHOW;
	}

	/** Not offered to Google (hidden or redirected). */
	public static function is_hidden_term( $term ): bool {
		return $term instanceof WP_Term && self::SHOW !== self::term_state( (int) $term->term_id );
	}

	/** @return int[] */
	public static function hidden_term_ids(): array {
		return array_map( 'intval', array_keys( self::map()['terms'] ) );
	}

	/** @return int[] categories that are not real addresses (left out of the shop's chips). */
	public static function redirected_term_ids(): array {
		return array_map( 'intval', array_keys( array_filter( self::map()['terms'], static fn( $row ) => self::REDIRECT === $row[0] ) ) );
	}

	/** The state of a link (menu entry, breadcrumb) on this site; another site's link is « show ». */
	public static function url_state( string $url ): string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== $host && self::bare_host( $host ) !== self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return self::SHOW;
		}
		if ( ! Delicat_Builder_V9_SEO::active() ) {
			return self::SHOW;
		}
		return self::map()['paths'][ self::path( $url ) ] ?? self::SHOW;
	}

	/**
	 * A menu entry's address as visitors should get it: unchanged, the real page a copy stands for (a category
	 * repeating one product leads to that product), or null when the entry only led to a fake page or a supplier
	 * name (it would land on the home page or the shop anyway).
	 */
	public static function menu_link( string $url ): ?string {
		if ( self::REDIRECT !== self::url_state( $url ) ) {
			return self::with_slash( $url );
		}
		$to = (string) ( self::map()['targets'][ self::path( $url ) ] ?? '' );
		$dead = array( self::path( home_url( '/' ) ) );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$dead[] = self::path( (string) wc_get_page_permalink( 'shop' ) );
		}
		return '' === $to || in_array( self::path( $to ), $dead, true ) ? null : $to;
	}

	/**
	 * « /sondaj » → « /sondaj/ » when the store's addresses end with a slash: the live drawer entry cost every
	 * visitor a 301 on each click. Other sites, files (« .pdf »), anchors and empty paths are left alone.
	 */
	public static function with_slash( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) || '/' === substr( $parts['path'], -1 ) || false !== strpos( basename( $parts['path'] ), '.' ) ) {
			return $url;
		}
		if ( ! empty( $parts['host'] ) && self::bare_host( (string) $parts['host'] ) !== self::bare_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return $url;
		}
		if ( empty( $parts['host'] ) && 0 !== strpos( $url, '/' ) ) {
			return $url; // « tel: », « mailto: », « #… » or a relative path
		}
		if ( '/' !== substr( (string) get_option( 'permalink_structure' ), -1 ) ) {
			return $url;
		}
		$at = strpos( $url, $parts['path'] ) + strlen( $parts['path'] );
		return substr( $url, 0, $at ) . '/' . substr( $url, $at );
	}

	public static function bare_host( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	public static function index_tags(): bool {
		return ! empty( Delicat_Builder_V9_SEO::settings()['index_tags'] );
	}

	/** Robots for the current request: true when it must not be indexed. */
	public static function hide_current(): bool {
		if ( is_front_page() || ( function_exists( 'is_shop' ) && is_shop() ) ) {
			return false;
		}
		if ( is_singular( array( 'page', 'post' ) ) ) {
			return self::SHOW !== self::post_state( (int) get_queried_object_id() );
		}
		if ( is_category() || ( function_exists( 'is_product_category' ) && is_product_category() ) ) {
			return self::is_hidden_term( get_queried_object() );
		}
		if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
			return ! self::index_tags() || self::is_hidden_term( get_queried_object() );
		}
		return false;
	}

	/** @return int[] page and post ids the sitemap leaves out. */
	public static function sitemap_excluded_posts(): array {
		return array_values( array_unique( array_merge( array_map( 'intval', array_keys( self::map()['posts'] ) ), self::duplicate_ids() ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Redirects                                                           */
	/* ------------------------------------------------------------------ */

	/** Where a redirected address leads. */
	public static function target( $object ): string {
		if ( $object instanceof WP_Post ) {
			return home_url( '/' );
		}
		if ( ! $object instanceof WP_Term ) {
			return '';
		}
		$terms = self::map()['terms'];
		return self::resolve_target( $object, (string) ( $terms[ (int) $object->term_id ][2] ?? '' ), $terms );
	}

	/** @param array<int,array> $terms the states being built or cached (an ancestor that is « show » can be the target). */
	private static function resolve_target( WP_Term $object, string $hint, array $terms, ?array $singles = null ): string {
		if ( 0 === strpos( $hint, 'keeper:' ) ) {
			$keeper = get_term( (int) substr( $hint, 7 ), $object->taxonomy );
			$link   = $keeper instanceof WP_Term ? get_term_link( $keeper ) : '';
			if ( is_string( $link ) && '' !== $link ) {
				return $link;
			}
		}
		if ( 'home' === $hint || ! in_array( $object->taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			return home_url( '/' );
		}
		if ( 'product_cat' === $object->taxonomy && 'shop' !== $hint ) {
			$only = null === $singles ? self::single_product( (int) $object->term_id ) : (int) ( $singles[ (int) $object->term_id ] ?? 0 );
			if ( $only ) {
				return (string) get_permalink( $only );
			}
			foreach ( get_ancestors( (int) $object->term_id, 'product_cat', 'taxonomy' ) as $ancestor ) {
				if ( ! isset( $terms[ (int) $ancestor ] ) ) {
					$link = get_term_link( (int) $ancestor, 'product_cat' );
					if ( is_string( $link ) ) {
						return $link;
					}
				}
			}
		}
		return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
	}

	/** template_redirect: a fake address answers 301 to the real page (not for people who edit pages). */
	public static function maybe_redirect(): void {
		if ( ! Delicat_Builder_V9_SEO::active() || is_admin() || wp_doing_ajax() || is_preview() || is_feed() || is_front_page() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || current_user_can( 'edit_pages' ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$object = get_queried_object();
		$state  = self::SHOW;
		if ( $object instanceof WP_Post && is_singular( array( 'page', 'post' ) ) ) {
			$state = self::post_state( (int) $object->ID );
		} elseif ( $object instanceof WP_Term && ( is_category() || is_tax( array( 'product_cat', 'product_tag' ) ) ) ) {
			$state = self::term_state( (int) $object->term_id );
		}
		if ( self::REDIRECT !== $state ) {
			return;
		}
		$here = $object instanceof WP_Term ? get_term_link( $object ) : get_permalink( $object );
		$to   = is_string( $here ) ? (string) ( self::map()['targets'][ self::path( $here ) ] ?? '' ) : '';
		$to   = '' !== $to ? $to : self::target( $object );
		if ( '' !== $to && self::path( $to ) !== self::path( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared.
			/* the choice can change at any time: neither the browser, Cloudflare nor LiteSpeed keeps this answer */
			nocache_headers();
			do_action( 'litespeed_control_set_nocache', 'Delicat SEO redirect' );
			wp_safe_redirect( $to, 301, 'Delicat SEO' );
			exit;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Duplicates                                                          */
	/* ------------------------------------------------------------------ */

	/** The original's address for a page that duplicates another, or ''. */
	public static function original_url( WP_Post $post ): string {
		$typed = trim( (string) get_post_meta( $post->ID, self::META_CANONICAL, true ) );
		if ( '' !== $typed ) {
			return 0 === strpos( $typed, '/' ) ? home_url( $typed ) : $typed;
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
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 100, 'fields' => 'ids', 'meta_key' => self::META_CANONICAL, 'no_found_rows' => true ) ) as $id ) {
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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 1 );
		/* The map starts over on content changes from hooks in the plugin's bootstrap
		 * (delicat_builder_v9_seo_index_watch), which run in every request profile: Quick Edit, Elementor,
		 * the importer and a supplier's REST sync do not load this module. */
		add_action( 'update_option_' . Delicat_Builder_V9_SEO::OPTION, array( __CLASS__, 'settings_saved' ) );
		add_action( 'add_option_' . Delicat_Builder_V9_SEO::OPTION, array( __CLASS__, 'settings_saved' ) );
	}

	public static function sitemap_taxonomies( $taxonomies ) {
		if ( is_array( $taxonomies ) && Delicat_Builder_V9_SEO::active() && ! self::index_tags() ) {
			unset( $taxonomies['product_tag'] );
		}
		return $taxonomies;
	}

	public static function sitemap_terms_args( $args, $taxonomy ) {
		if ( ! is_array( $args ) || ! Delicat_Builder_V9_SEO::active() || ! in_array( $taxonomy, array( 'product_cat', 'category', 'product_tag' ), true ) ) {
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
		foreach ( array_merge( array( $term ), (array) $terms ) as $candidate ) {
			if ( $candidate instanceof WP_Term && ! self::is_hidden_term( $candidate ) ) {
				return $candidate;
			}
		}
		return $term;
	}

	/** WooCommerce's visible trail without hidden or redirected categories (addresses only, no lookups). */
	public static function breadcrumb_trail( $crumbs ) {
		if ( ! is_array( $crumbs ) || ! Delicat_Builder_V9_SEO::active() || empty( self::map()['paths'] ) ) {
			return $crumbs;
		}
		$last = count( $crumbs ) - 1;
		$out  = array();
		foreach ( array_values( $crumbs ) as $i => $crumb ) {
			if ( $i < $last && is_array( $crumb ) && ! empty( $crumb[1] ) && self::SHOW !== self::url_state( (string) $crumb[1] ) ) {
				continue;
			}
			$out[] = $crumb;
		}
		return $out;
	}

	/**
	 * SEO-06: one author for breadcrumb data on product, category and shop pages. WooCommerce's
	 * JSON printer turned « Jeux & Recharges » into « Jeux &amp;amp; Recharges »; Delicat SEO
	 * writes the same trail in plain text there. Elsewhere WooCommerce's data stays.
	 */
	public static function breadcrumb_markup( $markup ) {
		$catalogue = ( function_exists( 'is_product' ) && is_product() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) || ( function_exists( 'is_shop' ) && is_shop() );
		return Delicat_Builder_V9_SEO::active() && $catalogue ? array() : $markup;
	}

	/** New choices change robots tags, menus and redirects inside cached pages: start them over. */
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

	/**
	 * The screen posts one choice per listed item (« default » = follow the rules); items not on
	 * the screen keep their stored choice. Saving through anything else (an import, or WordPress
	 * sanitizing a second time when the option is first created) passes the clean array through.
	 */
	public static function sanitize( array $input, array $current ): array {
		if ( empty( $input['index_ui'] ) ) {
			$source = array_key_exists( 'seo_overrides', $input ) ? $input : $current;
			return array(
				'seo_overrides' => self::clean_overrides( $source['seo_overrides'] ?? null ),
				'index_tags'    => empty( $source['index_tags'] ) ? 0 : 1,
			);
		}
		$kept = self::clean_overrides( $current['seo_overrides'] ?? null );
		foreach ( array( 'post', 'term' ) as $kind ) {
			$choices = isset( $input[ 'choice_' . $kind ] ) && is_array( $input[ 'choice_' . $kind ] ) ? $input[ 'choice_' . $kind ] : array();
			foreach ( $choices as $id => $choice ) {
				$id = absint( $id );
				if ( $id <= 0 ) {
					continue;
				}
				unset( $kept[ $kind ][ $id ] );
				if ( in_array( $choice, self::STATES, true ) ) {
					$kept[ $kind ][ $id ] = $choice;
				}
			}
		}
		return array(
			'seo_overrides' => $kept,
			'index_tags'    => empty( $input['index_tags'] ) ? 0 : 1,
		);
	}

	private static function select( string $name, string $current, string $recommended ): string {
		$labels = array(
			self::SHOW     => 'proposée à Google',
			self::HIDE     => 'cachée de Google (reste ouverte)',
			self::REDIRECT => 'redirigée vers la vraie page',
		);
		$out = '<select name="' . esc_attr( $name ) . '"><option value="default"' . selected( '', $current, false ) . '>Recommandé : ' . esc_html( $labels[ $recommended ] ?? $labels[ self::SHOW ] ) . '</option>';
		foreach ( $labels as $value => $label ) {
			$out .= '<option value="' . esc_attr( $value ) . '"' . selected( $value, $current, false ) . '>' . esc_html( ucfirst( $label ) ) . '</option>';
		}
		return $out . '</select>';
	}

	public static function render_settings( string $opt ): void {
		$reco      = self::recommend();
		$over      = self::overrides();
		$protected = self::protected_ids();
		echo '<h2>Ce que Google voit</h2>';
		echo '<p style="max-width:900px">« Cachée » : la page s’ouvre toujours mais n’est plus proposée à Google ni au plan du site. « Redirigée » : l’adresse n’est pas une vraie page ; visiteurs et Google arrivent sur la vraie (le produit qu’une catégorie répète, la catégorie qu’elle copie, la boutique ou l’accueil), et elle disparaît des menus et des rayons. Rien n’est effacé ; vous (éditeurs) ouvrez toujours toutes les adresses. Les règles valent pour tous vos fournisseurs : une catégorie créée demain par une synchronisation est traitée sans rien faire.</p>';
		echo '<input type="hidden" name="' . esc_attr( $opt ) . '[index_ui]" value="1">';
		echo '<h3>Pages</h3><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Page</th><th>Pourquoi</th><th>Choix</th></tr></thead><tbody>';
		$pages = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true, 'update_post_term_cache' => false ) );
		foreach ( $pages as $post ) {
			if ( in_array( (int) $post->ID, $protected, true ) || ( 'post' === $post->post_type && ! isset( $reco['posts'][ (int) $post->ID ] ) && ! isset( $over['post'][ (int) $post->ID ] ) ) ) {
				continue; // articles: only the sample is listed
			}
			$row = $reco['posts'][ (int) $post->ID ] ?? array( self::SHOW, '' );
			$why = trim( $row[1] . ( '' !== self::original_url( $post ) ? ' double d’une autre page : Google est renvoyé vers l’originale' : '' ) );
			echo '<tr><td>' . esc_html( get_the_title( $post ) ?: $post->post_name ) . ' <code>/' . esc_html( $post->post_name ) . '/</code></td><td>' . esc_html( $why ) . '</td><td>' . self::select( $opt . '[choice_post][' . (int) $post->ID . ']', $over['post'][ (int) $post->ID ] ?? '', $row[0] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- select() escapes.
		}
		echo '</tbody></table>';
		echo '<h3>Catégories et étiquettes</h3><p>' . esc_html( sprintf( '%d catégories créées automatiquement par vos fournisseurs (adresses comme « delicat-topup-c9e2614d39 ») sont redirigées vers leur produit ; elles ne sont pas listées une par une.', (int) $reco['supplier_auto'] ) ) . '</p>';
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Catégorie</th><th>Pourquoi</th><th>Choix</th></tr></thead><tbody>';
		$listed = get_terms( array( 'taxonomy' => array( 'product_cat', 'category', 'product_tag' ), 'hide_empty' => false, 'orderby' => 'name' ) );
		$kinds  = array( 'product_cat' => 'produits', 'category' => 'articles', 'product_tag' => 'étiquette' );
		foreach ( is_array( $listed ) ? $listed : array() as $term ) {
			if ( ! $term instanceof WP_Term || ( preg_match( self::MACHINE_SLUG, $term->slug ) && ! isset( $over['term'][ (int) $term->term_id ] ) ) ) {
				continue;
			}
			$row = $reco['terms'][ (int) $term->term_id ] ?? array( self::SHOW, '', '' );
			if ( 'product_tag' === $term->taxonomy && self::SHOW === $row[0] && ! isset( $over['term'][ (int) $term->term_id ] ) ) {
				continue; // tags: only the copies are listed; the switch below covers the rest
			}
			echo '<tr><td>' . esc_html( html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' <code>' . esc_html( $term->slug ) . '</code> <span class="description">(' . esc_html( ( $kinds[ $term->taxonomy ] ?? $term->taxonomy ) . ', ' . (int) $term->count ) . ')</span></td><td>' . esc_html( $row[1] ) . '</td><td>' . self::select( $opt . '[choice_term][' . (int) $term->term_id . ']', $over['term'][ (int) $term->term_id ] ?? '', $row[0] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- select() escapes.
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="' . esc_attr( $opt ) . '[index_tags]" value="1" ' . checked( self::index_tags(), true, false ) . '> Proposer les pages d’étiquettes (« jeux », « gift-card »…) à Google</label> <span class="description">Décoché (recommandé) : elles répètent les catégories ; les étiquettes servent toujours aux badges.</span></p>';
		self::render_orphans();
	}

	/** Products whose every category is hidden or redirected: their breadcrumb stops at « Accueil ». */
	private static function render_orphans(): void {
		$hidden = self::hidden_term_ids();
		if ( empty( $hidden ) ) {
			return;
		}
		$orphans = array();
		$ids     = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1000, 'fields' => 'ids', 'no_found_rows' => true ) );
		update_object_term_cache( $ids, 'product' );
		foreach ( $ids as $id ) {
			$cats = get_the_terms( (int) $id, 'product_cat' );
			$cats = is_array( $cats ) ? array_map( static fn( $t ) => (int) $t->term_id, $cats ) : array();
			if ( ! array_diff( $cats, $hidden ) ) {
				$orphans[] = (int) $id;
			}
		}
		if ( empty( $orphans ) ) {
			return;
		}
		echo '<h3>Produits sans vraie catégorie (' . count( $orphans ) . ')</h3><p class="description" style="max-width:900px">Ils ne sont que dans des catégories de fournisseur (ou « Uncategorized ») : leur fil d’Ariane s’arrête à « Accueil » et ils manquent dans les rayons. Cochez une vraie catégorie (Jeux, Gift Card, Abonnement…) sur leur fiche ou en « Modification rapide » ; la catégorie du fournisseur peut rester.</p><p>';
		$links = array();
		foreach ( array_slice( $orphans, 0, 120 ) as $id ) {
			$links[] = '<a href="' . esc_url( (string) get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		}
		echo implode( ' · ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	}
}
