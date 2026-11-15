<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Cache {
	private static bool $product_invalidation_done = false;
	/** Set when a version change purged what it could reach. */
	public const UPGRADE_NOTICE = 'delicat_builder_v9_upgrade_purge_notice';

	public static function boot(): void {
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'upgrade_notice' ) );
			add_action( 'admin_post_delicat_builder_v9_dismiss_upgrade_notice', array( __CLASS__, 'dismiss_upgrade_notice' ) );
		}
		/* RC80: nothing purged on upgrade, so a new plugin version never
		 * reached the browser until somebody clicked the manual purge button.
		 * The live homepage was serving HTML cached before RC72, which is why
		 * emoji were still rendering there while product pages — which happened
		 * not to be cached — showed the sweep working correctly. Every release
		 * since then was invisible on the store's most-visited page. */
		add_action( 'init', array( __CLASS__, 'maybe_purge_after_upgrade' ), 11 );
		/* App Tuning writes a :root block into the head of every cached page,
		 * so changing a size has to invalidate them the same way design does. */
		add_action( 'update_option_delicat_builder_v9_app_tuning', array( __CLASS__, 'purge_everything' ), 20 );
		add_action( 'save_post_product', array( __CLASS__, 'invalidate_products' ), 20, 3 );
		add_action( 'wp', array( __CLASS__, 'maybe_force_fresh_preview' ), 1 );
		add_action( 'update_option_delicat_builder_v9_design', array( __CLASS__, 'design_option_changed' ), 20, 2 );
		add_action( 'update_option_delicat_builder_v9_performance', array( __CLASS__, 'performance_option_changed' ), 20, 2 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'invalidate_products' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'invalidate_products' ), 20 );
		add_action( 'save_post_product_variation', array( __CLASS__, 'invalidate_products' ), 20, 3 );
		/* Stock: a quantity change purges the product page only. A stock STATUS change
		 * (in stock / out of stock) also refreshes listings, term pages and fragments. */
		add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'stock_update_started' ), 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'stock_update_started' ), 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'stock_changed' ), 20 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'stock_changed' ), 20 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'stock_status_changed' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'stock_status_changed' ), 20, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'maybe_invalidate_terms' ), 20, 6 );
		foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
			add_action( 'created_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ), 20, 3 );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ), 20, 3 );
			add_action( 'delete_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ), 20, 4 );
		}
		add_action( 'admin_post_delicat_builder_v9_purge', array( __CLASS__, 'handle_manual_purge' ) );
		/* 9.14.0 (S-1): background supplier saves and order stock changes purge the product page only. */
		Delicat_Builder_V9_Cache_Guard::boot();
		/* 9.14.0 (X-M1): a cached storefront page lives at most 10 h, so the guest nonces it carries stay valid
		 * now that pages are no longer purged every 30 s. Only ever lowers a longer TTL. */
		add_action( 'litespeed_control_finalize', array( __CLASS__, 'cap_public_ttl' ), 1000 );
	}

	/** 9.14.0 (X-M1): longest public cache life of a storefront page, in seconds (10 h). */
	public const PUBLIC_TTL_CAP = 36000;

	/**
	 * LiteSpeed › Control::finalize() fires litespeed_control_finalize for cacheable responses only; its
	 * get_ttl() (custom TTL, front-page TTL or the public TTL) is read through the litespeed_control_ttl filter
	 * and lowered with litespeed_control_set_ttl when longer than the cap. REST answers and feeds keep theirs.
	 */
	public static function cap_public_ttl( $esi = false ): void {
		unset( $esi );
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_ajax() || ( function_exists( 'is_feed' ) && is_feed() ) ) {
			return;
		}
		$cap = (int) apply_filters( 'delicat_builder_v9_public_ttl_cap', self::PUBLIC_TTL_CAP );
		$ttl = (int) apply_filters( 'litespeed_control_ttl', 0 );
		if ( $cap > 0 && $ttl > $cap ) {
			do_action( 'litespeed_control_set_ttl', $cap, 'Delicat Builder: storefront page cached 10 h at most (guest nonces)' );
		}
	}

	/**
	 * Tell the merchant what this plugin could not clear for them.
	 *
	 * Deliberately not dismissed automatically after a page load: an upgrade is
	 * exactly when someone is looking at the site on their phone, seeing the
	 * previous version, and concluding the update broke it.
	 */
	public static function upgrade_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$version = (string) get_transient( self::UPGRADE_NOTICE );
		if ( '' === $version ) {
			return;
		}

		$cf      = get_option( 'delicat_builder_v9_cloudflare', array() );
		$cf_here = is_array( $cf ) && ! empty( $cf['enabled'] ) && ! empty( $cf['zone_id'] );

		printf(
			'<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p>'
			. '<a class="button" href="%4$s">%5$s</a></p></div>',
			esc_html( sprintf( /* translators: %s: plugin version. */ __( 'Delicat Builder %s installed.', 'delicat-builder-v9' ), $version ) ),
			esc_html__( 'Its stylesheets and scripts have new filenames, so any page cached before the update still asks for the old ones and still shows the old interface.', 'delicat-builder-v9' ),
			esc_html(
				$cf_here
					? __( 'LiteSpeed and Cloudflare have been purged. If the site still looks wrong on your phone, it is that phone\'s own cache: reload the page or open it in a private tab.', 'delicat-builder-v9' )
					: __( 'LiteSpeed has been purged. Cloudflare has not, because no API token is set here — purge it from your Cloudflare dashboard. Then reload on your phone, or open the site in a private tab.', 'delicat-builder-v9' )
			),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=delicat_builder_v9_dismiss_upgrade_notice' ), 'delicat_builder_v9_dismiss_upgrade_notice' ) ),
			esc_html__( 'Got it', 'delicat-builder-v9' )
		);
	}

	public static function dismiss_upgrade_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_dismiss_upgrade_notice' );
		delete_transient( self::UPGRADE_NOTICE );
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	public static function version(): int {
		return max( 1, (int) get_option( 'delicat_builder_v9_cache_version', 1 ) );
	}

	public static function key( string $namespace, array $payload = array() ): string {
		$raw = $namespace . '|' . self::version() . '|' . wp_json_encode( $payload );
		return 'dbv9_' . substr( hash( 'sha256', $raw ), 0, 40 );
	}

	/** Products whose stock wc_update_product_stock() is changing in this request. */
	private static array $stock_updates = array();

	public static function stock_update_started( $product ): void {
		$id = $product instanceof WC_Product ? absint( $product->get_id() ) : absint( is_numeric( $product ) ? $product : 0 );
		if ( $id > 0 ) {
			self::$stock_updates[ $id ] = true;
		}
	}

	/**
	 * A quantity change alone (each paid order of a stock-managed gift card or game key)
	 * no longer bumps the site-wide cache version or purges shop, archive and term
	 * pages: only the product's own page shows the quantity.
	 */
	public static function stock_changed( $product ): void {
		$id = $product instanceof WC_Product ? absint( $product->get_id() ) : absint( is_numeric( $product ) ? $product : 0 );
		unset( self::$stock_updates[ $id ] );
		try {
			if ( Delicat_Builder_V9_Cache_Guard::narrow() ) {
				Delicat_Builder_V9_Cache_Guard::product_only( $id );
				return;
			}
			$page_id = self::product_page_id( $id );
			if ( $page_id <= 0 ) {
				return;
			}
			/* 9.14.0 (S-1): this page's own tag. litespeed_purge_post expands (purge-post_* rules) to the front
			 * page, the home, « all pages » and the date archive, and LiteSpeed's backend_purge adds every
			 * category of the product: a quantity is shown on the product page only. */
			do_action( 'litespeed_purge', 'Po.' . $page_id );
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				do_action( 'litespeed_purge_url', $url );
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/** In stock / out of stock changes what listings show: full product invalidation. */
	public static function stock_status_changed( $product_id, $status = '', $product = null ): void {
		unset( $status );
		$product_id = absint( $product_id );
		if ( Delicat_Builder_V9_Cache_Guard::narrow() ) {
			/* 9.14.0 (S-1): inside a background or order window the product page is purged at once; a whole
			 * product going in or out of stock (not a single variation: its parent follows in WooCommerce's
			 * deferred sync) refreshes the listings within 10 minutes. */
			$variation = $product instanceof WC_Product ? $product->is_type( 'variation' ) : 'product_variation' === get_post_type( $product_id );
			Delicat_Builder_V9_Cache_Guard::product_only( $product_id, ! $variation );
			return;
		}
		self::invalidate_products( $product_id );
	}

	/** The page that shows a product: its own, or its parent's for a variation. */
	public static function product_page_id( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$type = get_post_type( $id );
		if ( 'product_variation' === $type ) {
			$id   = absint( wp_get_post_parent_id( $id ) );
			$type = $id > 0 ? get_post_type( $id ) : '';
		}
		return 'product' === $type ? $id : 0;
	}

	public static function invalidate_products( ...$args ): void {
		$product_id = 0;
		foreach ( $args as $arg ) {
			if ( $arg instanceof WC_Product ) {
				$product_id = absint( $arg->get_id() );
				break;
			}
			if ( is_numeric( $arg ) && absint( $arg ) > 0 ) {
				$product_id = absint( $arg );
				break;
			}
		}

		/* 9.14.0 (S-1): a background or order save with no catalogue change purges the product page only and
		 * keeps the fragment-cache generation. The once-per-request flag below is left alone, so a structural
		 * save later in the same request still takes the wide path. A product term change is structural. */
		if ( $product_id > 0 && ! doing_action( 'set_object_terms' ) && Delicat_Builder_V9_Cache_Guard::narrow() ) {
			Delicat_Builder_V9_Cache_Guard::product_only( $product_id );
			return;
		}

		/* The save inside wc_update_product_stock() (a paid order reducing stock) is a
		 * stock change, not an edit: stock_changed() purges the product page once the
		 * change completes, and a status change reaches stock_status_changed(). */
		if (
			$product_id > 0
			&& isset( self::$stock_updates[ $product_id ] )
			&& in_array( current_filter(), array( 'woocommerce_update_product', 'woocommerce_update_product_variation' ), true )
		) {
			unset( self::$stock_updates[ $product_id ] );
			return;
		}

		// Woo can fire several product-update hooks in the same request.
		// Purge once per request to avoid repeated DB/cache work.
		if ( self::$product_invalidation_done ) {
			return;
		}
		self::$product_invalidation_done = true;

		/* A variation has no page or terms of its own: purge its parent's, not every
		 * term archive of the store. */
		if ( $product_id > 0 && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = absint( wp_get_post_parent_id( $product_id ) );
		}

		/* RC18: this runs inside checkout (stock reduction fires the Woo hooks
		 * above). A purge-time exception must never abort order processing or
		 * surface as a fatal in a bootstrap-critical file. */
		try {
			self::bump_version();
			self::purge_builder_product_pages();
			self::purge_product_archives( $product_id );
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	public static function maybe_invalidate_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand' ), true ) ) {
			self::invalidate_products( $object_id, $taxonomy );
		}
	}

	public static function taxonomy_changed( ...$args ): void {
		// Category/tag names and hierarchy affect search shortcuts and product presentation.
		self::invalidate_products();
	}

	public static function bump_version(): void {
		$current = self::version();
		update_option( 'delicat_builder_v9_cache_version', $current + 1, false );
	}


	public static function purge_page( int $page_id, bool $bump = true ): void {
		if ( ! self::purge_page_cache_only( $page_id ) ) {
			return;
		}

		if ( $bump ) {
			self::bump_version();
		}
	}

	private static function purge_page_cache_only( int $page_id ): bool {
		if ( $page_id <= 0 || 'page' !== get_post_type( $page_id ) ) {
			return false;
		}

		clean_post_cache( $page_id );
		wp_cache_delete( $page_id, 'posts' );
		wp_cache_delete( $page_id, 'post_meta' );

		$url = get_permalink( $page_id );
		do_action( 'litespeed_purge_post', $page_id );
		if ( is_string( $url ) && '' !== $url ) {
			do_action( 'litespeed_purge_url', $url );
		}
		return true;
	}

	private static function purge_builder_product_pages(): void {
		foreach ( self::builder_product_page_ids() as $page_id ) {
			self::purge_page_cache_only( $page_id );
		}
	}

	/** Builder pages whose layout shows product data (cards, carousels, prices). @return int[] */
	private static function builder_product_page_ids(): array {
		if ( ! class_exists( 'Delicat_Builder_V9_Pages', false ) ) {
			// RC39.13: the META_ENABLED constant below would fatal if the Pages
			// module was quarantined by the guarded loader. Purging nothing is
			// safe — page caches simply expire through the version bump.
			return array();
		}

		$page_ids = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 250,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => Delicat_Builder_V9_Pages::META_ENABLED,
						'value' => '1',
					),
				),
			)
		);

		$out = array();
		foreach ( array_map( 'absint', (array) $page_ids ) as $page_id ) {
			if (
				$page_id > 0
				&& class_exists( 'Delicat_Builder_V9_Pages' )
				&& Delicat_Builder_V9_Pages::page_needs_product_data( $page_id )
			) {
				$out[] = $page_id;
			}
		}
		return $out;
	}

	/**
	 * 9.14.0 (S-1): the debounced listing refresh after background price or stock-status changes
	 * (Delicat_Builder_V9_Cache_Guard, at most once every 10 minutes). Purges the front page, the shop and
	 * product archive, the categories of these products and the Builder pages that show products, and starts
	 * a new fragment-cache generation (cards and carousels carry prices). Pages are purged by tag and address,
	 * never with litespeed_purge_post, whose purge-post_* rules would add « all pages » and the date archive.
	 *
	 * @param int[] $product_ids Product (parent) ids marked dirty.
	 */
	public static function refresh_listings( array $product_ids ): void {
		try {
			self::bump_version();
			$tags = array( 'F', 'WC_S', 'PT.product', 'REST' );
			$urls = array();
			foreach ( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) as $product_id ) {
				foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
					if ( ! taxonomy_exists( $taxonomy ) ) {
						continue;
					}
					$terms = get_the_terms( $product_id, $taxonomy );
					if ( ! is_array( $terms ) ) {
						continue;
					}
					foreach ( $terms as $term ) {
						$term_ids = array( (int) $term->term_id );
						if ( 'product_cat' === $taxonomy ) {
							/* LiteSpeed tags a category page with its own id and every parent's (woocommerce.cls.php set_tag()). */
							$term_ids = array_merge( $term_ids, array_map( 'intval', (array) get_ancestors( (int) $term->term_id, 'product_cat' ) ) );
						}
						foreach ( $term_ids as $term_id ) {
							$tags[] = 'WC_T.' . $term_id;
							$tags[] = 'T.' . $term_id;
							$link   = $term_id === (int) $term->term_id ? get_term_link( $term ) : get_term_link( $term_id, $taxonomy );
							if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
								$urls[] = $link;
							}
						}
					}
				}
			}
			do_action( 'litespeed_purge', array_values( array_unique( $tags ) ) );

			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$urls[] = wc_get_page_permalink( 'shop' );
			}
			$urls[] = get_post_type_archive_link( 'product' );
			foreach ( self::builder_product_page_ids() as $page_id ) {
				clean_post_cache( $page_id );
				$urls[] = get_permalink( $page_id );
			}
			/* Addresses: LiteSpeed's URL tag, and the Cloudflare bridge (it listens to litespeed_purge_url). */
			foreach ( array_values( array_unique( array_filter( $urls, 'is_string' ) ) ) as $url ) {
				if ( '' !== $url ) {
					do_action( 'litespeed_purge_url', $url );
				}
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

public static function purge_product_archives( int $product_id = 0 ): void {
	$urls = array();

	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$shop = wc_get_page_permalink( 'shop' );
		if ( is_string( $shop ) && '' !== $shop ) {
			$urls[] = $shop;
		}
	}

	$archive = get_post_type_archive_link( 'product' );
	if ( is_string( $archive ) && '' !== $archive ) {
		$urls[] = $archive;
	}

	if ( $product_id > 0 && 'product' === get_post_type( $product_id ) ) {
		$product_url = get_permalink( $product_id );
		if ( is_string( $product_url ) && '' !== $product_url ) {
			$urls[] = $product_url;
		}
		do_action( 'litespeed_purge_post', $product_id );

		foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $product_id, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}
	} else {
		// Settings/profile changes affect archive presentation globally.
		// Purge a bounded set of product taxonomy archives, not the whole site.
		foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'number'     => 500,
					'fields'     => 'all',
				)
			);
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}
	}

	foreach ( array_values( array_unique( array_filter( $urls ) ) ) as $url ) {
		do_action( 'litespeed_purge_url', $url );
	}
}

	public static function preview_url( int $page_id ): string {
		$url = get_permalink( $page_id );
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		return add_query_arg(
			array(
				'delicat_builder_preview' => 1,
				'dbv9v'                  => rawurlencode( DELICAT_BUILDER_V9_VERSION ),
				'dbv9t'                  => time(),
			),
			$url
		);
	}

	public static function maybe_force_fresh_preview(): void {
		if (
			empty( $_GET['delicat_builder_preview'] )
			|| '1' !== sanitize_text_field( wp_unslash( $_GET['delicat_builder_preview'] ) )
			|| ! is_singular( 'page' )
		) {
			return;
		}

		$page_id = get_queried_object_id();
		if ( ! $page_id || ! current_user_can( 'edit_post', $page_id ) ) {
			return;
		}

		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'Delicat Builder staging preview' );
	}


	public static function performance_option_changed( $old_value, $new_value ): void {
		if ( wp_json_encode( $old_value ) === wp_json_encode( $new_value ) ) {
			return;
		}
		self::bump_version();
		self::purge_all_builder_pages();
	}

	private static function purge_all_builder_pages(): void {
		$ids = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => array( 'publish', 'private', 'draft' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 500,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => '_delicat_builder_v9_enabled',
						'value' => '1',
					),
				),
			)
		);

		foreach ( array_map( 'absint', (array) $ids ) as $page_id ) {
			if ( $page_id > 0 ) {
				self::purge_page_cache_only( $page_id );
			}
		}
	}

	public static function design_option_changed( $old_value, $new_value ): void {
		$ids = array();
		foreach ( array( $old_value, $new_value ) as $value ) {
			if ( is_array( $value ) && ! empty( $value['selected_page_id'] ) ) {
				$ids[] = absint( $value['selected_page_id'] );
			}
		}
		foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $page_id ) {
			self::purge_page( $page_id );
		}
	}

	/**
	 * RC80: purge once per plugin version.
	 *
	 * The version is written before anything is purged, so a fatal or a
	 * concurrent request during the purge cannot leave it running on every
	 * page load. The option is autoloaded because it is read on every init.
	 */
	public static function maybe_purge_after_upgrade(): void {
		$current = defined( 'DELICAT_BUILDER_V9_VERSION' ) ? (string) DELICAT_BUILDER_V9_VERSION : '';
		if ( '' === $current ) {
			return;
		}
		$seen = (string) get_option( 'delicat_builder_v9_purged_version', '' );
		if ( $seen === $current ) {
			return;
		}
		/* A REST-light request (app polling) does not load Legal and Stability, which
		 * act on this once-per-version purge: leave it to the next page request.
		 * V10: so do the session JSON, wc-ajax and WP-Cron profiles; the page or wp-admin
		 * request that follows a release (the update itself is a wp-admin request) runs it. */
		if ( defined( 'DELICAT_BUILDER_V9_REST_LIGHT' ) && DELICAT_BUILDER_V9_REST_LIGHT ) {
			return;
		}
		if ( defined( 'DELICAT_BUILDER_V9_PROFILE' ) && ! in_array( DELICAT_BUILDER_V9_PROFILE, array( 'storefront', 'admin' ), true ) ) {
			return;
		}
		update_option( 'delicat_builder_v9_purged_version', $current, true );
		/* V10: the one release purge. DBP_Kernel::deploy_check() used to flush the whole object cache and
		 * purge LiteSpeed a second time on the same request; it now only renews its generation token. The
		 * Builder cache version invalidates every Builder key, so the rest of Redis stays warm. */
		self::purge_everything();
		/* LiteSpeed's combined CSS/JS and critical/unique CSS were built from the previous release's markup
		 * (the purges the Pro deploy check sent until 9.14.4). */
		do_action( 'litespeed_purge_all_cssjs' );
		do_action( 'litespeed_purge_all_ccssucss' );

		/*
		 * LiteSpeed has just been told, and so has Cloudflare IF the merchant
		 * put its API token in this plugin. Nothing here can reach a Cloudflare
		 * that was never configured, a different CDN, or the copy already sitting
		 * on a customer's phone.
		 *
		 * That gap is not theoretical. Assets are content-addressed, so an
		 * upgrade changes their filenames - and a page cached before the upgrade
		 * goes on asking for the old ones and rendering the old interface. It is
		 * why "the site looks wrong after updating" keeps coming back, and why
		 * the answer keeps being "purge your caches". Say so, once per version,
		 * where the person who can do it will see it.
		 */
		set_transient( self::UPGRADE_NOTICE, $current, WEEK_IN_SECONDS );
		/**
		 * Fires once after the plugin version changes and caches are cleared.
		 *
		 * @param string $current Version now installed.
		 * @param string $seen    Version that was purged previously.
		 */
		do_action( 'delicat_builder_v9_version_purged', $current, $seen );
	}

	/**
	 * Bump the internal fragment version, drop the builder page caches and ask
	 * LiteSpeed to clear its own. Safe to call when LiteSpeed is not installed:
	 * the action simply has no listener.
	 */
	public static function purge_everything(): void {
		self::bump_version();
		self::purge_all_builder_pages();
		do_action( 'litespeed_purge_all' );
	}

	public static function handle_manual_purge(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to purge this cache.', 'delicat-builder-v9' ), 403 );
		}

		check_admin_referer( 'delicat_builder_v9_purge' );
		self::bump_version();
		self::purge_all_builder_pages();
		do_action( 'litespeed_purge_all' );

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_cache_purged',
				'notice',
				array( 'cache_version' => self::version() )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'delicat-builder-v9',
					'purged' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

/**
 * 9.14.0 (S-1) CACHE GUARD: the home, shop and category pages stay in LiteSpeed's page cache between real
 * catalogue edits.
 *
 * Every supplier sync save (Digital Gateway every 30 s, Top-Up Connector every minute, Shop2TopUp) and every
 * paid order on a stock-managed product reached WooCommerce's woocommerce_update_product(_variation) and
 * *_set_stock hooks. The Builder answered with a site-wide purge and a new fragment-cache generation, and
 * LiteSpeed's own WooCommerce integration expanded each litespeed_purge_post through the « Auto Purge Rules
 * For Publish/Update » to the front page, the home, « all pages », the date archive and every category.
 *
 * A save made in the background (WP-Cron, Action Scheduler, WP-CLI, the Gateway's sync batch, a REST or AJAX
 * save by someone who cannot edit products) with no catalogue change, and wc_update_product_stock() (orders,
 * refunds, cancellations) open a narrow window. Inside it:
 *  - LiteSpeed's purge-post_* rules are forced off for the request (litespeed_conf_force, conf.cls.php
 *    force_option(): request-local) and put back when the last window closes;
 *  - listing tags that LiteSpeed's WooCommerce integration requests through the litespeed_purge action while a
 *    window is open (backend_purge(): WC_T.<category>) are dropped from the purge header (litespeed_purge_tags,
 *    purge.cls.php _append_prefix()), unless something outside a window asked for the same tag;
 *  - the Builder purges the product's own page (Po.<id> + its address) and the REST tag the app's catalogue
 *    reads, and keeps its fragment-cache generation;
 *  - a price change, or a whole product going in or out of stock, marks the listings dirty: one refresh at most
 *    every 10 minutes (a single WP-Cron event; a non-autoloaded option holding a set of ids and a timestamp,
 *    never a counter, never the object cache).
 * Admin edits, publish / unpublish, trash and every structural change keep the wide path unchanged.
 *
 * WooCommerce fires *_set_stock and *_set_stock_status INSIDE save() (data store handle_updated_props(),
 * before woocommerce_update_product(_variation)), and wc_update_product_stock() wraps its own save() between
 * *_before_set_stock and *_set_stock: windows are a stack of {save|stock, object} entries, a save closes only
 * its own entry and *_set_stock closes only a stock entry of the same object sitting on top.
 */
final class Delicat_Builder_V9_Cache_Guard {
	public const DIRTY = 'delicat_builder_v9_listing_dirty';
	public const HOOK  = 'delicat_builder_v9_listing_refresh';

	/** LiteSpeed « Auto Purge Rules For Publish/Update » (base.cls.php O_PURGE_POST_*). */
	private const LS_KEYS = array( 'purge-post_all', 'purge-post_f', 'purge-post_h', 'purge-post_p', 'purge-post_pwrp', 'purge-post_a', 'purge-post_y', 'purge-post_m', 'purge-post_d', 'purge-post_t', 'purge-post_pt' );
	/** Changes that alter what a listing or a page shows beyond price and stock: today's wide path. */
	private const STRUCTURAL = array( 'status', 'catalog_visibility', 'name', 'slug', 'image_id', 'gallery_image_ids', 'category_ids', 'tag_ids', 'featured', 'menu_order', 'parent_id', 'description', 'short_description', 'date_created', 'post_password' );
	/** Changes that alter a card's price: the product page at once, the listings within 10 minutes. */
	private const LISTING = array( 'price', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to' );

	/** @var array<int,array{src:string,obj:int,page:int,kind:string}> kind: page | listing | inherit | wide */
	private static array $stack = array();
	private static bool $narrow = false;
	/** @var array<string,mixed> LiteSpeed values forced off while narrow, to put back. */
	private static array $saved = array();
	/** litespeed_conf_force is missing or did not take: drop the purge-post_* tags at header time instead. */
	private static bool $fallback = false;
	private static bool $had_narrow = false;
	private static bool $wide_seen = false;
	/** @var array<string,bool> */
	private static array $narrow_tags = array();
	/** @var array<string,bool> */
	private static array $wide_tags = array();
	/** @var array<int,bool> */
	private static array $purged = array();
	/** @var array<int,bool> */
	private static array $marked = array();
	/** @var array<int,bool> Parents of variations saved inside a window: their deferred sync follows it. */
	private static array $sync_parents = array();
	private static int $as = 0;
	private static bool $booted = false;
	private static bool $runner = false;

	public static function boot(): void {
		if ( self::$booted || ! defined( 'LSCWP_V' ) ) {
			return;
		}
		self::$booted = true;
		add_action( 'action_scheduler_begin_execute', array( __CLASS__, 'as_begin' ), 0, 0 );
		add_action( 'action_scheduler_after_execute', array( __CLASS__, 'as_end' ), 0, 0 );
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'as_end' ), 0, 0 );
		foreach ( array( 'product', 'product_variation' ) as $type ) {
			add_action( "woocommerce_before_{$type}_object_save", array( __CLASS__, 'before_save' ), 0, 1 );
			add_action( "woocommerce_after_{$type}_object_save", array( __CLASS__, 'after_save' ), PHP_INT_MAX, 1 );
		}
		add_action( 'woocommerce_product_before_set_stock', array( __CLASS__, 'before_stock' ), 0, 1 );
		add_action( 'woocommerce_variation_before_set_stock', array( __CLASS__, 'before_stock' ), 0, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'after_stock' ), PHP_INT_MAX, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'after_stock' ), PHP_INT_MAX, 1 );
		/* What was asked outside a window (LiteSpeed's own listeners run at priority 10). */
		add_action( 'litespeed_purge', array( __CLASS__, 'seen_purge' ), 1, 1 );
		add_action( 'litespeed_purge_post', array( __CLASS__, 'seen_wide' ), 1, 0 );
		add_action( 'litespeed_purge_all', array( __CLASS__, 'seen_wide' ), 1, 0 );
		add_action( 'delete_post', array( __CLASS__, 'seen_wide' ), 1, 0 );
		add_action( 'wp_trash_post', array( __CLASS__, 'seen_wide' ), 1, 0 );
		add_action( 'transition_post_status', array( __CLASS__, 'seen_transition' ), 1, 2 );
		add_filter( 'litespeed_purge_tags', array( __CLASS__, 'purge_tags' ), 10, 2 );
		/* Everything still open at shutdown is stale (an exception inside a save): WooCommerce's deferred
		 * product sync (shutdown@10) is classified on its own. */
		add_action( 'shutdown', array( __CLASS__, 'reset' ), 1, 0 );
		add_action( self::HOOK, array( __CLASS__, 'refresh_listings' ) );
	}

	/**
	 * Action Scheduler's async queue runner (a foreign admin-ajax action) loads this file only, not
	 * Delicat_Builder_V9_Cache::boot(). Its product and stock handlers are what call product_only() inside a narrow
	 * window: the product page (Po.<id> + REST) and, for a price change or a whole product going in or out of stock,
	 * the listing dirty mark. Without them the guard narrowed LiteSpeed's own purges there and nothing refreshed the
	 * home, shop and category listings. The same handlers are registered here at the same priorities, narrow path
	 * only: outside a window (a structural save) nothing is added and LiteSpeed's own rules purge as in 9.13.0.
	 */
	public static function boot_runner(): void {
		self::boot();
		if ( ! self::$booted || self::$runner ) {
			return;
		}
		self::$runner = true;
		add_action( 'woocommerce_update_product', array( __CLASS__, 'runner_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'runner_saved' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'runner_saved' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'runner_saved' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'runner_stock_status' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'runner_stock_status' ), 20, 3 );
	}

	/** Runner twin of Cache::invalidate_products() / stock_changed() inside a narrow window. */
	public static function runner_saved( $product ): void {
		if ( ! self::$narrow ) {
			return;
		}
		$id = $product instanceof WC_Product ? absint( $product->get_id() ) : absint( is_numeric( $product ) ? $product : 0 );
		if ( $id <= 0 ) {
			return;
		}
		try {
			self::product_only( $id );
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/** Runner twin of Cache::stock_status_changed() inside a narrow window. */
	public static function runner_stock_status( $product_id, $status = '', $product = null ): void {
		unset( $status );
		if ( ! self::$narrow ) {
			return;
		}
		$product_id = absint( $product_id );
		if ( $product_id <= 0 ) {
			return;
		}
		try {
			$variation = $product instanceof WC_Product ? $product->is_type( 'variation' ) : 'product_variation' === get_post_type( $product_id );
			self::product_only( $product_id, ! $variation );
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	public static function as_begin(): void {
		self::$as++;
	}

	public static function as_end(): void {
		self::$as = max( 0, self::$as - 1 );
	}

	/** A save nobody made by hand in wp-admin: supplier syncs, scheduled jobs, the app or a webhook. */
	public static function background(): bool {
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || self::$as > 0 || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		/* The Digital Gateway's sync batch (contract K34: class-dfr-catalog.php sync batch). */
		if ( apply_filters( 'delicat_app_suspend_catalog_bumps', false ) ) {
			return true;
		}
		$remote = wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_DOING_AJAX' ) && WC_DOING_AJAX );
		return $remote && ! current_user_can( 'edit_products' );
	}

	public static function before_save( $product ): void {
		$kind = 'inherit';
		$page = 0;
		if ( $product instanceof WC_Product ) {
			$page = self::page_of( $product );
			/* WooCommerce syncs a variable parent at shutdown (WC_Post_Data::do_deferred_product_sync()), after
			 * the batch flag, the Action Scheduler action or the order's stock window that saved its variation. */
			$deferred = doing_action( 'shutdown' ) && isset( self::$sync_parents[ (int) $product->get_id() ] );
			if ( self::active() && ( $deferred || self::background() ) ) {
				$keys = array_keys( (array) $product->get_changes() );
				if ( ! $product->get_id() || array_intersect( $keys, self::STRUCTURAL ) ) {
					$kind = 'wide';
				} elseif ( array_intersect( $keys, self::LISTING ) || ( in_array( 'stock_status', $keys, true ) && ! $product->is_type( 'variation' ) ) ) {
					$kind = 'listing';
				} else {
					$kind = 'page';
				}
			}
		}
		/* Always pushed (« inherit » when this save does not decide), so after_save never closes an outer window. */
		self::$stack[] = array( 'src' => 'save', 'obj' => is_object( $product ) ? spl_object_id( $product ) : 0, 'page' => $page, 'kind' => $kind );
		self::sync();
		if ( self::$narrow && $product instanceof WC_Product && $product->is_type( 'variation' ) && $page > 0 ) {
			self::$sync_parents[ $page ] = true;
		}
	}

	public static function after_save( $product ): void {
		$obj = is_object( $product ) ? spl_object_id( $product ) : 0;
		for ( $i = count( self::$stack ) - 1; $i >= 0; $i-- ) {
			if ( 'save' === self::$stack[ $i ]['src'] && self::$stack[ $i ]['obj'] === $obj ) {
				array_splice( self::$stack, $i, 1 );
				break;
			}
		}
		self::sync();
	}

	/** wc_update_product_stock(): an order, a refund or a cancellation changes the quantity. */
	public static function before_stock( $product ): void {
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		self::$stack[] = array( 'src' => 'stock', 'obj' => spl_object_id( $product ), 'page' => self::page_of( $product ), 'kind' => self::active() ? 'page' : 'inherit' );
		self::sync();
	}

	/** Closes the stock window of wc_update_product_stock(); a *_set_stock fired inside save() closes nothing. */
	public static function after_stock( $product ): void {
		$top = end( self::$stack );
		if ( $top && 'stock' === $top['src'] && is_object( $product ) && spl_object_id( $product ) === $top['obj'] ) {
			array_pop( self::$stack );
			self::sync();
		}
	}

	public static function reset(): void {
		self::$stack = array();
		self::sync();
	}

	/** True while a narrow window is open: the innermost entry that decides wins (« inherit » defers outward). */
	public static function narrow(): bool {
		return self::$narrow;
	}

	private static function active(): bool {
		return defined( 'LSCWP_V' ) && has_action( 'litespeed_purge' );
	}

	private static function decide(): bool {
		for ( $i = count( self::$stack ) - 1; $i >= 0; $i-- ) {
			$kind = self::$stack[ $i ]['kind'];
			if ( 'wide' === $kind ) {
				return false;
			}
			if ( 'page' === $kind || 'listing' === $kind ) {
				return true;
			}
		}
		return false;
	}

	private static function sync(): void {
		$want = self::decide();
		if ( $want && ! self::$narrow ) {
			self::$narrow     = true;
			self::$had_narrow = true;
			self::force_off();
		} elseif ( ! $want && self::$narrow ) {
			self::$narrow = false;
			self::restore();
		}
	}

	private static function force_off(): void {
		self::$saved = array();
		if ( ! has_action( 'litespeed_conf_force' ) || ! has_filter( 'litespeed_conf' ) ) {
			self::$fallback = true;
			return;
		}
		foreach ( self::LS_KEYS as $key ) {
			$value = apply_filters( 'litespeed_conf', $key );
			if ( $value === $key || null === $value || ! $value ) {
				continue; // unknown key (no LiteSpeed API) or already off
			}
			self::$saved[ $key ] = $value;
			do_action( 'litespeed_conf_force', $key, false );
			if ( apply_filters( 'litespeed_conf', $key ) ) {
				self::$fallback = true; // a LITESPEED_CONF constant or a load_option filter wins over the force
			}
		}
	}

	private static function restore(): void {
		foreach ( self::$saved as $key => $value ) {
			do_action( 'litespeed_conf_force', $key, $value );
		}
		self::$saved = array();
	}

	/** Called by Delicat_Builder_V9_Cache instead of the generation bump and the wide purges while narrow(). */
	public static function product_only( int $id, bool $listing = false ): void {
		$page = Delicat_Builder_V9_Cache::product_page_id( $id );
		if ( $page <= 0 ) {
			return;
		}
		if ( ! isset( self::$purged[ $page ] ) ) {
			self::$purged[ $page ] = true;
			/* This page only, plus the REST tag: the App API's public catalogue reads are cached by LiteSpeed
			 * (cache-rest) and refreshed by nothing else. Never litespeed_purge_post. */
			do_action( 'litespeed_purge', array( 'Po.' . $page, 'REST' ) );
			$url = get_permalink( $page );
			if ( is_string( $url ) && '' !== $url ) {
				do_action( 'litespeed_purge_url', $url );
			}
		}
		if ( $listing || self::listing_window( $page ) ) {
			self::mark_dirty( $page );
		}
	}

	private static function listing_window( int $page ): bool {
		foreach ( self::$stack as $entry ) {
			if ( 'listing' === $entry['kind'] && $entry['page'] === $page ) {
				return true;
			}
		}
		return false;
	}

	private static function page_of( WC_Product $product ): int {
		$id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		return max( 0, $id );
	}

	/**
	 * One refresh at most every 10 minutes. get_option + update_option without a lock: two jobs writing at the
	 * same moment can lose one id, and with it only that product's category tags (the refresh still purges the
	 * front page, the shop and the product archive).
	 */
	private static function mark_dirty( int $page ): void {
		if ( isset( self::$marked[ $page ] ) ) {
			return;
		}
		self::$marked[ $page ] = true;
		$dirty = get_option( self::DIRTY, array() );
		$dirty = is_array( $dirty ) ? $dirty : array();
		$ids   = array_values( array_filter( array_map( 'absint', (array) ( $dirty['ids'] ?? array() ) ) ) );
		if ( ! in_array( $page, $ids, true ) ) {
			$ids[] = $page;
		}
		update_option(
			self::DIRTY,
			array(
				'since' => (int) ( $dirty['since'] ?? time() ),
				'ids'   => array_slice( array_values( array_unique( $ids ) ), -200 ),
			),
			false
		);
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$delay = max( 60, (int) apply_filters( 'delicat_builder_v9_listing_refresh_delay', 600 ) );
			wp_schedule_single_event( time() + $delay, self::HOOK );
		}
	}

	public static function refresh_listings(): void {
		/* The set is consumed here: a later change in the same cron run must be able to mark its product again. */
		self::$marked = array();
		$dirty = get_option( self::DIRTY, array() );
		delete_option( self::DIRTY );
		$ids = is_array( $dirty ) ? array_values( array_filter( array_map( 'absint', (array) ( $dirty['ids'] ?? array() ) ) ) ) : array();
		if ( $ids ) {
			Delicat_Builder_V9_Cache::refresh_listings( $ids );
		}
	}

	/* ------------------------------------------------------------------ purge header */

	public static function seen_purge( $tags ): void {
		foreach ( (array) $tags as $tag ) {
			$tag = (string) $tag;
			if ( self::$narrow ) {
				self::$narrow_tags[ $tag ] = true;
			} else {
				self::$wide_tags[ $tag ] = true;
			}
		}
		if ( ! self::$narrow ) {
			self::$wide_seen = true;
		}
	}

	public static function seen_wide(): void {
		if ( ! self::$narrow ) {
			self::$wide_seen = true;
		}
	}

	public static function seen_transition( $new_status, $old_status ): void {
		if ( ( 'publish' === $new_status || 'publish' === $old_status ) && ! self::$narrow ) {
			self::$wide_seen = true;
		}
	}

	private static function listing_tag( string $tag ): bool {
		return in_array( $tag, array( 'F', 'H', 'PGS', 'PGSRP', 'WC_S' ), true ) || (bool) preg_match( '/^(?:WC_T|T|A|D|PT)\./', $tag );
	}

	/**
	 * litespeed_purge_tags (purge.cls.php _append_prefix(), on every header build, the cron queue included): the
	 * tags carry LiteSpeed's blog prefix (« _F », « _WC_T.84 »). Drops listing tags that only a narrow window asked
	 * for; without a working force (fallback) also the purge-post_* tags, while nothing outside a window purged.
	 */
	public static function purge_tags( $tags, $is_private = false ) {
		if ( $is_private || ! self::$had_narrow || ! is_array( $tags ) ) {
			return $tags;
		}
		$strip_rules = self::$fallback && ! self::$wide_seen;
		$out         = array();
		foreach ( $tags as $tag ) {
			$bare = (string) preg_replace( '/^\d*_/', '', (string) $tag );
			if ( '*' !== $tag && self::listing_tag( $bare ) && ! isset( self::$wide_tags[ $bare ] ) ) {
				if ( isset( self::$narrow_tags[ $bare ] ) || $strip_rules ) {
					continue;
				}
			}
			$out[] = $tag;
		}
		return $out;
	}
}
