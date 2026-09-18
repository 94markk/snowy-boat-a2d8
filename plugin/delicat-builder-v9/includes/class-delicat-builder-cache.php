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
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'invalidate_products' ), 20 );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'invalidate_products' ), 20 );
		add_action( 'set_object_terms', array( __CLASS__, 'maybe_invalidate_terms' ), 20, 6 );
		foreach ( array( 'product_cat', 'product_tag', 'product_brand' ) as $taxonomy ) {
			add_action( 'created_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ), 20, 3 );
			add_action( 'edited_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ), 20, 3 );
			add_action( 'delete_' . $taxonomy, array( __CLASS__, 'taxonomy_changed' ), 20, 4 );
		}
		add_action( 'admin_post_delicat_builder_v9_purge', array( __CLASS__, 'handle_manual_purge' ) );
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

	public static function invalidate_products( ...$args ): void {
		// Woo can fire several product-update hooks in the same request.
		// Purge once per request to avoid repeated DB/cache work.
		if ( self::$product_invalidation_done ) {
			return;
		}
		self::$product_invalidation_done = true;

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
		if ( ! class_exists( 'Delicat_Builder_V9_Pages', false ) ) {
			// RC39.13: the META_ENABLED constant below would fatal if the Pages
			// module was quarantined by the guarded loader. Purging nothing is
			// safe — page caches simply expire through the version bump.
			return;
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

		foreach ( array_map( 'absint', (array) $page_ids ) as $page_id ) {
			if (
				$page_id > 0
				&& class_exists( 'Delicat_Builder_V9_Pages' )
				&& Delicat_Builder_V9_Pages::page_needs_product_data( $page_id )
			) {
				self::purge_page_cache_only( $page_id );
			}
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
		update_option( 'delicat_builder_v9_purged_version', $current, true );
		self::purge_everything();

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
