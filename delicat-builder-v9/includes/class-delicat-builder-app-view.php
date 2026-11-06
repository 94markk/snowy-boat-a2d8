<?php
/**
 * App-browsing view (9.3.7, Google Play): the links the Builder draws itself, left out of
 * a website page opened from the app.
 *
 * Delicat App API 2.32.0 (Delicat_App_Browse) marks a website page opened from the app with
 * the cookie dlc_app_view and answers apply_filters( 'delicat_app_is_app_view', false ). It
 * already filters WordPress menus, category lists, the shop and the Builder footer's columns;
 * the links the Builder draws on its own (header icon menu « Finance », drawer « Exchange
 * USDT - USD », home section « home-exchange-carousel », footer, bottom bar) never pass
 * through those filters. While that answer is true:
 *  - a link, menu item, item line or home section whose target is a hidden product category
 *    (Delicat_App_Catalog::hidden_term_ids(); without the API catalogue, the slugs exchange,
 *    finance, exchange-usdt-usd and categories whose slug or name contains usdt, crypto,
 *    bitcoin, exchange or finance) or a hidden product, or whose label or URL names exchange,
 *    finance (financier…), usdt, crypto, bitcoin, paypal (anywhere) or wise, meru (as a word),
 *    is dropped: header icon menu, drawer and legacy menu (items, quick actions, promo),
 *    [delicat_bottom_nav], footer columns and legal links (« Conditions Exchange et Wallet »),
 *    home sections and their item lines and buttons, carousel « Voir tout », the optional
 *    shell's announcement and search shortcuts; hidden products leave product carousels and
 *    the hero type-ahead; so do the home testimonial cards (seeded lines and real reviews) about a
 *    hidden product or category, or naming a hidden subject, and the topic badge « Finance » on
 *    product cards (the carousel's card caches are keyed apart while seen from the app);
 *  - while apply_filters( 'delicat_app_view_hides_credit_gifts', false ) is true as well, so
 *    are the links to the website's credit pages (label or URL naming portefeuille or wallet,
 *    or « argent » as a word: « Mon portefeuille », /my-wallet/, « Envoyer de l'argent ») and
 *    the drawer's balance card; the bottom bar's « Wallet » slot opens « Commandes » instead,
 *    the footer's « Delicat Wallet » payment chip reads « Crédit boutique Délicat » and the
 *    drawer's guest line, the account page's login card and the default « Comment ça marche »
 *    step (« paie avec ton crédit boutique ») no longer say wallet or portefeuille;
 *  - the Builder's server fragment cache is neither read nor written.
 * Without that answer nothing here filters anything: every page is what 9.3.6 drew, byte for
 * byte (tests/app_view_smoke.php).
 *
 * Accents, case, HTML entities and URL encoding are ignored, as the API does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_App_View {

	/** Labels and URLs containing one of these are hidden. */
	public const STEMS = array( 'exchange', 'financ', 'usdt', 'crypto', 'bitcoin', 'paypal' );

	/** Hidden only as a word of their own. */
	public const WORDS = array( 'wise', 'meru' );

	/** Credit pages, while gifts are hidden: anywhere in the label or URL… */
	public const WALLET_STEMS = array( 'portefeuille', 'wallet' );

	/** …or as a word of its own (« Envoyer de l'argent », /envoyer-argent/). */
	public const WALLET_WORDS = array( 'argent' );

	/** Hidden categories when the API catalogue is not there (its own default setting). */
	public const FALLBACK_SLUGS = array( 'exchange', 'finance', 'exchange-usdt-usd' );

	/** …and categories whose slug or name contains one of these. */
	public const FALLBACK_NAME_STEMS = array( 'usdt', 'crypto', 'bitcoin', 'exchange', 'finance' );

	/** Home sections that only ever list hidden products. */
	public const SECTION_IDS = array( 'home-exchange-carousel' );

	/** Product category bases a link can use (the legacy French base still 301s). */
	public const CATEGORY_BASES = array( 'product-category', 'categorie-produit' );

	/**
	 * Section types whose « items » lines carry a link: type => array( URL column, label
	 * columns, explode() limit used by the renderer ).
	 */
	private const LINE_COLUMNS = array(
		'category_chips' => array( 2, array( 1, 3 ), 4 ),
		'favorites'      => array( 3, array( 1, 2 ), 5 ),
		'banner'         => array( 3, array( 0, 1, 2 ), PHP_INT_MAX ),
		'testimonials'   => array( 4, array( 0, 2 ), 5 ),
	);

	/** Hidden category slugs for this request (null: not worked out yet). */
	private static $slugs = null;

	public static function boot(): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'wp', array( __CLASS__, 'prime' ), 99 );
		}
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'delicat_builder_v9_badge_topics', array( __CLASS__, 'filter_badge_topics' ), 99 );
		}
	}

	/** Whether this page (or the website AJAX it calls) is seen from the app. */
	public static function active(): bool {
		return function_exists( 'apply_filters' ) && (bool) apply_filters( 'delicat_app_is_app_view', false );
	}

	/** Seen from the app while credit gifts are not offered there (Google Play build). */
	public static function hides_gifts(): bool {
		return self::active() && (bool) apply_filters( 'delicat_app_view_hides_credit_gifts', false );
	}

	/**
	 * wp (before template_redirect): work the hidden categories out while the API's category
	 * filter still leaves every term in place, so hidden_term_ids() never sees a filtered list.
	 */
	public static function prime(): void {
		if ( self::active() ) {
			self::hidden_slugs();
		}
	}

	/** Forget this request's answers (tests). */
	public static function reset(): void {
		self::$slugs = null;
	}

	// ------------------------------------------------------------------
	// Words
	// ------------------------------------------------------------------

	/** Whether a label or URL names exchange / finance / usdt / crypto / bitcoin / paypal / wise / meru. */
	public static function names_hidden( $text ): bool {
		return self::names( $text, self::STEMS, self::WORDS );
	}

	/** Whether a label or URL names the website's credit pages (portefeuille / wallet / argent). */
	public static function names_wallet( $text ): bool {
		return self::names( $text, self::WALLET_STEMS, self::WALLET_WORDS );
	}

	private static function names( $text, array $stems, array $words ): bool {
		$text = is_scalar( $text ) ? (string) $text : '';
		if ( '' === trim( $text ) ) {
			return false;
		}
		$text = rawurldecode( html_entity_decode( strip_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
		$text = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text );
		foreach ( $stems as $stem ) {
			if ( false !== strpos( $text, $stem ) ) {
				return true;
			}
		}
		$found = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		return (bool) array_intersect( $words, is_array( $found ) ? $found : array() );
	}

	// ------------------------------------------------------------------
	// Targets
	// ------------------------------------------------------------------

	private static function catalog_available(): bool {
		return class_exists( 'Delicat_App_Catalog' ) && is_callable( array( 'Delicat_App_Catalog', 'hidden_term_ids' ) );
	}

	/** @return string[] Slugs of the hidden product categories (lower case, decoded). */
	private static function hidden_slugs(): array {
		if ( is_array( self::$slugs ) ) {
			return self::$slugs;
		}
		$slugs = array();
		if ( self::catalog_available() ) {
			try {
				$ids = Delicat_App_Catalog::hidden_term_ids();
			} catch ( Throwable $error ) {
				$ids = array();
			}
			foreach ( is_array( $ids ) ? $ids : array() as $id ) {
				$term = function_exists( 'get_term' ) ? get_term( (int) $id, 'product_cat' ) : null;
				if ( is_object( $term ) && isset( $term->slug ) && is_scalar( $term->slug ) && '' !== (string) $term->slug ) {
					$slugs[] = strtolower( rawurldecode( (string) $term->slug ) );
				}
			}
		} else {
			$slugs = self::FALLBACK_SLUGS;
		}
		self::$slugs = array_values( array_unique( $slugs ) );
		return self::$slugs;
	}

	/** Whether a product category slug is hidden. */
	public static function slug_hidden( $slug ): bool {
		$slug = is_scalar( $slug ) ? strtolower( rawurldecode( trim( (string) $slug ) ) ) : '';
		if ( '' === $slug ) {
			return false;
		}
		if ( in_array( $slug, self::hidden_slugs(), true ) ) {
			return true;
		}
		if ( self::catalog_available() ) {
			return false; /* the catalogue's answer, through hidden_slugs() */
		}
		foreach ( self::FALLBACK_NAME_STEMS as $stem ) {
			if ( false !== strpos( $slug, $stem ) ) {
				return true;
			}
		}
		$term = function_exists( 'get_term_by' ) ? get_term_by( 'slug', $slug, 'product_cat' ) : null;
		if ( is_object( $term ) && isset( $term->name ) && is_scalar( $term->name ) ) {
			$name = strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $term->name ) : (string) $term->name );
			foreach ( self::FALLBACK_NAME_STEMS as $stem ) {
				if ( false !== strpos( $name, $stem ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether a URL opens a hidden category (…/product-category/<slug>/…, ?product_cat=<slug>)
	 * or, when the API can tell, a hidden product.
	 */
	public static function url_hidden( $url ): bool {
		$url = is_scalar( $url ) ? trim( (string) $url ) : '';
		if ( '' === $url || '#' === $url[0] ) {
			return false;
		}
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
			if ( isset( $query['product_cat'] ) && is_scalar( $query['product_cat'] ) ) {
				foreach ( explode( ',', (string) $query['product_cat'] ) as $slug ) {
					if ( self::slug_hidden( $slug ) ) {
						return true;
					}
				}
			}
		}
		$segments = array_values( array_filter( explode( '/', strtolower( rawurldecode( (string) ( $parts['path'] ?? '' ) ) ) ), 'strlen' ) );
		$bases    = self::CATEGORY_BASES;
		if ( function_exists( 'wc_get_permalink_structure' ) ) {
			$structure = (array) wc_get_permalink_structure();
			$base      = strtolower( trim( (string) ( $structure['category_rewrite_slug'] ?? '' ), '/' ) );
			if ( '' !== $base ) {
				$bases[] = $base;
			}
		}
		foreach ( $segments as $at => $segment ) {
			if ( ! in_array( $segment, $bases, true ) ) {
				continue;
			}
			foreach ( array_slice( $segments, $at + 1 ) as $slug ) {
				if ( 'page' === $slug ) {
					break;
				}
				if ( self::slug_hidden( $slug ) ) {
					return true;
				}
			}
			break;
		}
		if ( is_callable( array( 'Delicat_App_Browse', 'link_is_hidden' ) ) ) {
			try {
				return (bool) Delicat_App_Browse::link_is_hidden( $url );
			} catch ( Throwable $error ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Whether a link goes, seen from the app: hidden target, hidden words in its URL or one
	 * of its labels, and, while gifts are hidden, the credit pages. Callers check active().
	 *
	 * @param mixed $url    Link.
	 * @param array $labels Its label, subtitle, badge…
	 */
	public static function hides_link( $url, array $labels = array() ): bool {
		if ( self::names_hidden( $url ) || self::url_hidden( $url ) ) {
			return true;
		}
		foreach ( $labels as $label ) {
			if ( self::names_hidden( $label ) ) {
				return true;
			}
		}
		if ( self::hides_gifts() ) {
			if ( self::names_wallet( $url ) ) {
				return true;
			}
			foreach ( $labels as $label ) {
				if ( self::names_wallet( $label ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** A product the app hides (API catalogue), or whose name names a hidden subject. */
	private static function product_hidden( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		if ( is_callable( array( 'Delicat_App_Catalog', 'product_is_hidden' ) ) ) {
			try {
				if ( Delicat_App_Catalog::product_is_hidden( $product_id ) ) {
					return true;
				}
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
		return self::names_hidden( function_exists( 'get_the_title' ) ? get_the_title( $product_id ) : '' );
	}

	// ------------------------------------------------------------------
	// Filters (each returns its input untouched when the page is not seen from the app)
	// ------------------------------------------------------------------

	/**
	 * Links or menu items (arrays): the hidden ones go.
	 *
	 * @param mixed    $items      Items.
	 * @param string   $url_key    Key of the link.
	 * @param string[] $label_keys Keys of the texts shown with it.
	 * @param bool     $keep_keys  Keep the array keys (keyed lists), else a list.
	 * @return mixed
	 */
	public static function filter_items( $items, string $url_key = 'url', array $label_keys = array( 'label' ), bool $keep_keys = false ) {
		if ( ! is_array( $items ) || ! $items || ! self::active() ) {
			return $items;
		}
		$kept = array();
		foreach ( $items as $key => $item ) {
			if ( is_array( $item ) ) {
				$labels = array();
				foreach ( $label_keys as $label_key ) {
					$labels[] = $item[ $label_key ] ?? '';
				}
				if ( self::hides_link( $item[ $url_key ] ?? '', $labels ) ) {
					continue;
				}
			}
			if ( $keep_keys ) {
				$kept[ $key ] = $item;
			} else {
				$kept[] = $item;
			}
		}
		return $kept;
	}

	/**
	 * Footer columns: the hidden links go, and a column left without links with them.
	 *
	 * @param mixed $columns Columns ( title, links[ label, url ] ).
	 * @return mixed
	 */
	public static function filter_footer_columns( $columns ) {
		if ( ! is_array( $columns ) || ! $columns || ! self::active() ) {
			return $columns;
		}
		$out = array();
		foreach ( $columns as $column ) {
			if ( is_array( $column ) && isset( $column['links'] ) && is_array( $column['links'] ) && $column['links'] ) {
				$column['links'] = self::filter_items( $column['links'] );
				if ( ! $column['links'] ) {
					continue;
				}
			}
			$out[] = $column;
		}
		return $out;
	}

	/**
	 * A link (a « Voir tout », a button): '' when it goes, else as it came.
	 *
	 * @param mixed $url    Link.
	 * @param array $labels Texts shown with it.
	 * @return mixed
	 */
	public static function link_or_empty( $url, array $labels = array() ) {
		if ( ! is_scalar( $url ) || '' === trim( (string) $url ) || ! self::active() ) {
			return $url;
		}
		return self::hides_link( $url, $labels ) ? '' : $url;
	}

	/**
	 * Product ids of a carousel: the hidden products go. Card caches are keyed by these ids,
	 * so the website's cached cards are never touched.
	 *
	 * @param mixed $ids Product ids.
	 * @return mixed
	 */
	public static function filter_product_ids( $ids ) {
		if ( ! is_array( $ids ) || ! $ids || ! self::active() ) {
			return $ids;
		}
		$kept = array();
		foreach ( $ids as $id ) {
			if ( self::product_hidden( (int) $id ) ) {
				continue;
			}
			$kept[] = $id;
		}
		return $kept;
	}

	/**
	 * Product rows (the hero type-ahead index: id, name, url…): the hidden products go.
	 *
	 * @param mixed  $rows Rows.
	 * @param string $key  Key of the product id.
	 * @return mixed
	 */
	public static function filter_product_rows( $rows, string $key = 'id' ) {
		if ( ! is_array( $rows ) || ! $rows || ! self::active() ) {
			return $rows;
		}
		$kept = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && ( self::product_hidden( (int) ( $row[ $key ] ?? 0 ) ) || self::names_hidden( $row['name'] ?? '' ) ) ) {
				continue;
			}
			$kept[] = $row;
		}
		return $kept;
	}

	/**
	 * Plain texts (search shortcuts): those naming a hidden subject go.
	 *
	 * @param mixed $texts Texts.
	 * @return mixed
	 */
	public static function filter_texts( $texts ) {
		if ( ! is_array( $texts ) || ! $texts || ! self::active() ) {
			return $texts;
		}
		return array_values(
			array_filter(
				$texts,
				static function ( $text ): bool {
					return ! self::names_hidden( $text );
				}
			)
		);
	}

	/**
	 * Testimonial cards (Renderer::testimonials, after Reviews::merge_items(): quote, name,
	 * place, rating, product, url): a card linking a hidden category or product, or whose
	 * product, place or quote names a hidden subject, goes. The reviews rail cache is not
	 * touched, so the website keeps every card.
	 *
	 * @param mixed $items Cards.
	 * @return mixed
	 */
	public static function filter_testimonials( $items ) {
		if ( ! is_array( $items ) || ! $items || ! self::active() ) {
			return $items;
		}
		$kept = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && self::hides_link( $item['url'] ?? '', array( $item['product'] ?? '', $item['place'] ?? '', $item['quote'] ?? '' ) ) ) {
				continue;
			}
			$kept[] = $item;
		}
		return $kept;
	}

	/**
	 * Topic badges (Badges::topics(), filter delicat_builder_v9_badge_topics, priority 99): a
	 * topic whose key or label names a hidden subject (« Finance ») is not drawn on a card.
	 *
	 * @param mixed $topics Topics ( key => label, icon, match ).
	 * @return mixed
	 */
	public static function filter_badge_topics( $topics ) {
		if ( ! is_array( $topics ) || ! $topics || ! self::active() ) {
			return $topics;
		}
		foreach ( $topics as $key => $topic ) {
			$label = is_array( $topic ) ? ( $topic['label'] ?? '' ) : '';
			if ( self::hides_link( '', array( is_string( $key ) ? $key : '', $label ) ) ) {
				unset( $topics[ $key ] );
			}
		}
		return $topics;
	}

	/**
	 * A cache key's payload (carousel cards): seen from the app the cards differ (topic
	 * badges), so the key says so; on the website the payload comes back as it was.
	 *
	 * @param array $payload Key payload.
	 * @return array
	 */
	public static function cache_context( array $payload ): array {
		if ( self::active() ) {
			$payload['app_view'] = self::hides_gifts() ? 2 : 1;
		}
		return $payload;
	}

	/**
	 * A Builder page layout (Renderer::render_layout): hidden sections go, hidden item lines
	 * and buttons inside the others go. Array keys are kept (section ids and order use them).
	 *
	 * @param array $layout Sanitized sections.
	 * @return array
	 */
	public static function filter_layout( array $layout ): array {
		if ( ! $layout || ! self::active() ) {
			return $layout;
		}
		foreach ( $layout as $key => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$section = self::filter_section( $section );
			if ( null === $section ) {
				unset( $layout[ $key ] );
			} else {
				$layout[ $key ] = $section;
			}
		}
		return $layout;
	}

	/** @return array|null The section as seen from the app, or null when it goes entirely. */
	private static function filter_section( array $section ) {
		$id   = is_scalar( $section['id'] ?? '' ) ? strtolower( (string) ( $section['id'] ?? '' ) ) : '';
		$type = is_scalar( $section['type'] ?? '' ) ? (string) ( $section['type'] ?? '' ) : '';
		if ( in_array( $id, self::SECTION_IDS, true ) ) {
			return null;
		}
		/* A section the app's own rules leave out of this view (Delicat App API 2.32.0: the
		 * « Programme Bon Kliyan » panel seen from the Google Play build while both its
		 * programmes are off there). */
		if ( function_exists( 'apply_filters' ) && apply_filters( 'delicat_app_view_hides_section', false, $type, $section ) ) {
			return null;
		}
		$content = is_array( $section['content'] ?? null ) ? $section['content'] : array();

		if ( 'products' === $type ) {
			$category = is_scalar( $content['category'] ?? '' ) ? trim( (string) ( $content['category'] ?? '' ) ) : '';
			if ( '' !== $category && ( self::names_hidden( $category ) || self::slug_hidden( function_exists( 'sanitize_title' ) ? sanitize_title( $category ) : $category ) ) ) {
				return null;
			}
			foreach ( array( 'eyebrow', 'title', 'subtitle', 'tag_text' ) as $field ) {
				if ( self::names_hidden( $content[ $field ] ?? '' ) ) {
					return null;
				}
			}
			$view_all = $content['view_all_url'] ?? '';
			if ( is_scalar( $view_all ) && '' !== trim( (string) $view_all ) && self::hides_link( $view_all ) ) {
				return null;
			}
		}

		if ( isset( self::LINE_COLUMNS[ $type ] ) && isset( $content['items'] ) && is_scalar( $content['items'] ) ) {
			list( $url_at, $label_at, $limit ) = self::LINE_COLUMNS[ $type ];
			$content['items'] = self::filter_lines( (string) $content['items'], $url_at, $label_at, $limit );
		}

		if ( 'banner' === $type && ( self::names_hidden( $content['title'] ?? '' ) || self::names_hidden( $content['text'] ?? '' ) || self::names_hidden( $content['button_text'] ?? '' ) ) ) {
			/* The banner's first card (title, text, button) goes; its other cards stay. */
			$content['title'] = '';
			$content['text']  = '';
		}

		/* Every button or link field (button_url, button_2_url, cta_url, view_all_url…) whose
		 * target or text is hidden loses its link, which every renderer reads as « no button ». */
		foreach ( $content as $field => $value ) {
			if ( ! is_string( $field ) || '_url' !== substr( $field, -4 ) || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}
			$text_field = substr( $field, 0, -4 ) . '_text';
			if ( self::hides_link( (string) $value, array( $content[ $text_field ] ?? '' ) ) ) {
				$content[ $field ] = '';
			}
		}

		$section['content'] = $content;
		return $section;
	}

	/** The « items » lines (« icon|label|url|… ») without the hidden ones. */
	private static function filter_lines( string $items, int $url_at, array $label_at, int $limit ): string {
		$lines = preg_split( '/\r\n|\r|\n/', $items );
		$kept  = array();
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$parts  = array_map( 'trim', explode( '|', (string) $line, $limit ) );
			$labels = array();
			foreach ( $label_at as $at ) {
				$labels[] = $parts[ $at ] ?? '';
			}
			if ( '' !== trim( (string) $line ) && self::hides_link( $parts[ $url_at ] ?? '', $labels ) ) {
				continue;
			}
			$kept[] = $line;
		}
		return implode( "\n", $kept );
	}
}

Delicat_Builder_V9_App_View::boot();
