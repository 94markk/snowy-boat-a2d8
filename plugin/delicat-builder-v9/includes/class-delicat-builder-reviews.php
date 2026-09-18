<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC51.28 — Client Reviews module.
 *
 * 1) Real customer reviews: logged-in clients submit an avis (stars + quote +
 *    city). Submissions are stored as a pending `delicat_review` post; an admin
 *    approves by publishing. Approved reviews are merged into the homepage
 *    "ILS NOUS FONT CONFIANCE" testimonials rail ahead of the seeded items.
 *
 * 2) Second top-up prompt: when a logged-in client has 2 or more completed
 *    orders, a smooth popup invites them (once) to leave a review. Detection is
 *    lazy — computed inside the AJAX prompt endpoint — so it works no matter
 *    which process (checkout, DDG watchdog loopback, admin) completed the
 *    order. Nothing here relies on WP-Cron.
 *
 * Cache safety: the popup CSS/JS are enqueued only for logged-in users, so the
 * anonymous page HTML served from LiteSpeed/Cloudflare stays byte-identical.
 * The submit nonce is never printed into page HTML; it is returned by the
 * per-user prompt endpoint. Requires PHP 8.5 (gated in the main plugin file).
 */
final class Delicat_Builder_V9_Reviews {

	const CPT            = 'delicat_review';
	const META_RATING    = '_dlc_review_rating';
	const META_CITY      = '_dlc_review_city';
	const META_USER      = '_dlc_review_user';
	const UMETA_DONE     = '_dlc_review_done';
	const UMETA_SNOOZE   = '_dlc_review_snooze_until';
	const UMETA_PROBE    = '_dlc_review_comment_probe';
	const UMETA_SHOWN    = '_dlc_review_prompt_shown';
	const PROBE_SECONDS  = 900;
	const MAX_QUOTE_LEN  = 280;
	const SNOOZE_SECONDS = 604800; // 7 days.
	/** RC63: city typed in the popup, stored on the WooCommerce review comment. */
	const CMETA_CITY     = '_dlc_review_city';
	/** RC66: product to invite a review for after a completed order. */
	const UMETA_DUE_PRODUCT = '_dlc_review_due_product';
	const CMETA_SOURCE   = '_dlc_review_source';
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		if ( did_action( 'init' ) ) {
			self::register_cpt();
		} else {
			add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		}

		add_action( 'wp_ajax_delicat_builder_v9_review_prompt', array( __CLASS__, 'ajax_prompt' ) );
		add_action( 'wp_ajax_nopriv_delicat_builder_v9_review_prompt', array( __CLASS__, 'ajax_prompt_guest' ) );
		add_action( 'wp_ajax_delicat_builder_v9_review_submit', array( __CLASS__, 'ajax_submit' ) );
		add_action( 'wp_ajax_delicat_builder_v9_review_snooze', array( __CLASS__, 'ajax_snooze' ) );
		/* RC65: purchases + reviewed products, fetched only when the popup opens. */
		add_action( 'wp_ajax_delicat_builder_v9_review_products', array( __CLASS__, 'ajax_products' ) );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 32 );
		if ( ! class_exists( 'Delicat_Builder_V9_Reviews_Kernel', false ) ) {
			add_action( 'transition_post_status', array( __CLASS__, 'maybe_purge_on_approval' ), 10, 3 );

			/* RC51.30: keep the rail in sync with WooCommerce product reviews. */
			add_action( 'transition_comment_status', array( __CLASS__, 'woo_review_status_changed' ), 10, 3 );
			add_action( 'edit_comment', array( __CLASS__, 'woo_review_edited' ) );
			add_action( 'wp_insert_comment', array( __CLASS__, 'woo_review_inserted' ), 10, 2 );
		}

		if ( is_admin() ) {
			add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
			add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_value' ), 10, 2 );
			add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'register_meta_box' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	public static function register_cpt(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Avis clients', 'delicat-builder-v9' ),
					'singular_name' => __( 'Avis client', 'delicat-builder-v9' ),
					'menu_name'     => __( 'Avis clients', 'delicat-builder-v9' ),
					'edit_item'     => __( 'Modérer l’avis', 'delicat-builder-v9' ),
					'search_items'  => __( 'Rechercher un avis', 'delicat-builder-v9' ),
					'not_found'     => __( 'Aucun avis pour le moment.', 'delicat-builder-v9' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'menu_position'       => 58,
				'menu_icon'           => 'dashicons-star-filled',
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Approved reviews in the testimonials item shape, newest first.
	 * Called by the renderer; must stay cheap (one small query, no meta_query).
	 */
	public static function published_items( int $limit = 8 ): array {
		if ( ! post_type_exists( self::CPT ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => self::CPT,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, min( 10, $limit ) ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$quote = trim( wp_strip_all_tags( (string) $post->post_content ) );
			$name  = trim( (string) $post->post_title );
			if ( '' === $quote || '' === $name ) {
				continue;
			}
			$items[] = array(
				'quote'   => sanitize_text_field( $quote ),
				'name'    => sanitize_text_field( $name ),
				'place'   => sanitize_text_field( (string) get_post_meta( $post->ID, self::META_CITY, true ) ),
				'rating'  => max( 1, min( 5, absint( get_post_meta( $post->ID, self::META_RATING, true ) ) ) ),
				'product' => '',
				'url'     => '',
			);
		}
		return $items;
	}

	public static function published_count(): int {
		if ( ! post_type_exists( self::CPT ) ) {
			return 0;
		}
		$counts = wp_count_posts( self::CPT );
		return isset( $counts->publish ) ? absint( $counts->publish ) : 0;
	}

	/* ------------------------------------------------------------------ */
	/* RC51.30 — WooCommerce product review sync                           */
	/* ------------------------------------------------------------------ */

	const RAIL_CACHE = 'dlc_rev_rail_v2'; // RC63: items carry product + url.

	/**
	 * Approved WooCommerce product reviews (wp-admin → Products → Reviews)
	 * mapped to the testimonials item shape. The product name fills the
	 * "place" line (e.g. SPOTIFY US) since Woo reviews carry no city.
	 * Only ratings >= the minimum (default 4) are shown on the trust rail.
	 */
	public static function woo_review_items( int $limit = 8 ): array {
		$min_rating = max( 1, min( 5, absint( apply_filters( 'delicat_builder_v9_min_review_rating', 4 ) ) ) );

		$comments = get_comments(
			array(
				'type'    => 'review',
				'status'  => 'approve',
				'number'  => max( 1, min( 20, $limit * 2 ) ),
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);

		$items = array();
		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof WP_Comment ) {
				continue;
			}
			$quote = trim( wp_strip_all_tags( (string) $comment->comment_content ) );
			$name  = trim( (string) $comment->comment_author );
			if ( '' === $quote || '' === $name ) {
				continue;
			}
			$rating = absint( get_comment_meta( (int) $comment->comment_ID, 'rating', true ) );
			if ( 0 === $rating ) {
				$rating = 5; // Legacy reviews without a stored rating.
			}
			if ( $rating < $min_rating ) {
				continue;
			}
			$product      = get_post( (int) $comment->comment_post_ID );
			$product_name = $product instanceof WP_Post ? (string) $product->post_title : '';
			/* RC63: the city typed in the popup leads; the product name is
			 * printed as a link to the product by the renderer. */
			$city  = trim( (string) get_comment_meta( (int) $comment->comment_ID, self::CMETA_CITY, true ) );
			$place = '' !== $city ? $city : $product_name;
			$url   = $product instanceof WP_Post && 'publish' === (string) $product->post_status ? (string) get_permalink( $product ) : '';
			if ( function_exists( 'mb_substr' ) ) {
				$quote = function_exists( 'mb_substr' ) ? mb_substr( $quote, 0, self::MAX_QUOTE_LEN ) : substr( $quote, 0, self::MAX_QUOTE_LEN );
			} else {
				$quote = substr( $quote, 0, self::MAX_QUOTE_LEN );
			}
			$items[] = array(
				'quote'   => sanitize_text_field( $quote ),
				'name'    => sanitize_text_field( $name ),
				'place'   => sanitize_text_field( $place ),
				'rating'  => max( 1, min( 5, $rating ) ),
				'product' => sanitize_text_field( $product_name ),
				'url'     => esc_url_raw( $url ),
			);
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}

	/** Approved WooCommerce product reviews, all ratings. */
	public static function woo_review_count(): int {
		$count = get_comments(
			array(
				'type'   => 'review',
				'status' => 'approve',
				'count'  => true,
			)
		);
		return absint( $count );
	}

	/**
	 * Combined rail payload with a 10-minute transient cache so uncached
	 * homepage renders stay fast. Invalidated on review approval changes.
	 */
	public static function rail_payload(): array {
		$cached = get_transient( self::RAIL_CACHE );
		if ( is_array( $cached ) && isset( $cached['items'], $cached['count'] ) ) {
			return $cached;
		}

		$payload = array(
			'items' => array_merge( self::published_items( 6 ), self::woo_review_items( 6 ) ),
			'count' => self::published_count() + self::woo_review_count(),
		);
		set_transient( self::RAIL_CACHE, $payload, 10 * MINUTE_IN_SECONDS );
		return $payload;
	}

	public static function flush_rail_cache(): void {
		delete_transient( self::RAIL_CACHE );
	}

	/** Woo review approved/unapproved or edited → refresh rail + purge homepage. */
	public static function woo_review_status_changed( $new_status, $old_status, $comment ): void {
		if ( ! $comment instanceof WP_Comment || 'review' !== (string) $comment->comment_type ) {
			return;
		}
		if ( 'approved' !== (string) $new_status && 'approved' !== (string) $old_status ) {
			return;
		}
		self::flush_rail_cache();
		self::purge_front_page();
		self::purge_product_page( absint( $comment->comment_post_ID ) );
	}

	public static function woo_review_edited( $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment instanceof WP_Comment && 'review' === (string) $comment->comment_type && '1' === (string) $comment->comment_approved ) {
			self::flush_rail_cache();
			self::purge_front_page();
			self::purge_product_page( absint( $comment->comment_post_ID ) );
		}
	}

	/** A review inserted already-approved (e.g. trusted author) skips transition_comment_status. */
	public static function woo_review_inserted( $comment_id, $comment ): void {
		if ( $comment instanceof WP_Comment && 'review' === (string) $comment->comment_type && '1' === (string) $comment->comment_approved ) {
			self::flush_rail_cache();
			self::purge_front_page();
			self::purge_product_page( absint( $comment->comment_post_ID ) );
		}
	}

	private static function purge_front_page(): void {
		$front_id = absint( get_option( 'page_on_front' ) );
		if ( $front_id > 0 && class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'purge_page' ) ) ) {
			Delicat_Builder_V9_Cache::purge_page( $front_id );
		}
		do_action( 'litespeed_purge_url', home_url( '/' ) );
	}

	/**
	 * RC65: the product page shows its reviews (RC63 block + Product JSON-LD)
	 * and is cached per URL, so an approved / edited review must purge that
	 * URL too, not only the front page.
	 */
	private static function purge_product_page( int $product_id ): void {
		if ( $product_id <= 0 || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		/* Product Turbo keys its server fragment on this stamp (server engine). */
		update_post_meta( $product_id, '_dlc_reviews_stamp', (string) time() );
		do_action( 'litespeed_purge_post', $product_id );
		$url = get_permalink( $product_id );
		if ( is_string( $url ) && '' !== $url ) {
			do_action( 'litespeed_purge_url', $url );
		}
	}

	/**
	 * Merge real reviews ahead of the admin-seeded testimonials, capped at 10.
	 * Order: popup submissions first, then WooCommerce product reviews, then seeds.
	 */
	public static function merge_items( array $seeded ): array {
		$real = self::rail_payload()['items'];
		if ( empty( $real ) ) {
			return $seeded;
		}
		return array_slice( array_merge( $real, $seeded ), 0, 10 );
	}

	/** Total shown next to "AVIS VÉRIFIÉS": approved popup avis + approved Woo reviews. */
	public static function total_verified_count(): int {
		return absint( self::rail_payload()['count'] );
	}

	/* ------------------------------------------------------------------ */
	/* Second top-up detection (lazy, cron-independent)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Has this client already commented?
	 *
	 * The popup's own flag first, then any comment they left on a product —
	 * a WooCommerce review written before this module existed counts, because
	 * from the client's side it is the same act. A positive answer is written
	 * into the DONE flag, so the count query runs once per client and never
	 * again; a negative answer is throttled, because the prompt endpoint runs
	 * on every page view.
	 */
	private static function client_has_reviewed( int $user_id ): bool {
		if ( '' !== (string) get_user_meta( $user_id, self::UMETA_DONE, true ) ) {
			return true;
		}

		$probe = absint( get_user_meta( $user_id, self::UMETA_PROBE, true ) );
		if ( $probe > 0 && ( time() - $probe ) < self::PROBE_SECONDS ) {
			return false;
		}

		$count = 0;
		try {
			$count = (int) get_comments(
				array(
					'user_id'   => $user_id,
					'post_type' => 'product',
					'status'    => 'all',
					'count'     => true,
				)
			);
		} catch ( Throwable $error ) {
			$count = 0;
		}

		if ( $count > 0 ) {
			update_user_meta( $user_id, self::UMETA_DONE, 'comment' );
			return true;
		}

		update_user_meta( $user_id, self::UMETA_PROBE, time() );
		return false;
	}

	/**
	 * RC43: the invitation is shown to every signed-in client who has never
	 * commented, on each of the three surfaces that load the popup — home,
	 * account and order-received. Nothing else gates it.
	 *
	 * What was removed on purpose: the two-completed-orders threshold, which
	 * silenced the prompt for exactly the new clients worth asking, and the
	 * seven-day snooze, which outlived the visit it was dismissed in. Leaving
	 * a review is now the only thing that stops it.
	 *
	 * A boutique that wants it softer sets a cooldown in seconds through
	 * `delicat_builder_v9_review_prompt_cooldown`, or turns the automatic
	 * invitation off entirely with `delicat_builder_v9_review_auto_prompt`.
	 */
	private static function prompt_is_due( int $user_id, bool $on_thanks ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! (bool) apply_filters( 'delicat_builder_v9_review_auto_prompt', true, $user_id, $on_thanks ) ) {
			return false;
		}
		if ( self::client_has_reviewed( $user_id ) ) {
			return false;
		}

		/*
		 * pro.16: honour the dismissal the popup already records.
		 *
		 * ajax_snooze() writes UMETA_SNOOZE on every "Plus tard", and
		 * order_completed() deletes it when a new purchase is worth asking
		 * about — both halves of a snooze were implemented. Nothing ever READ
		 * it, and the cooldown below defaults to 0, so for a signed-in client
		 * who has never left a review this method returned true on every
		 * home, account and order-received view, for ever. Dismissing the
		 * invitation bought the customer exactly one page load. On a store
		 * where most customers never review, that is a permanent popup on the
		 * homepage — the nag RC66's own comment says must never happen.
		 *
		 * Leaving a review still ends the invitation permanently
		 * (client_has_reviewed above). A completed order still re-invites
		 * immediately, because order_completed() clears this key as it queues
		 * the product. The cooldown filter is untouched for a boutique that
		 * configured its own rhythm.
		 */
		$snoozed_until = absint( get_user_meta( $user_id, self::UMETA_SNOOZE, true ) );
		if ( $snoozed_until > time() ) {
			return false;
		}

		$cooldown = (int) apply_filters( 'delicat_builder_v9_review_prompt_cooldown', 0, $user_id );
		if ( $cooldown > 0 ) {
			$last = absint( get_user_meta( $user_id, self::UMETA_SHOWN, true ) );
			if ( $last > 0 && ( time() - $last ) < $cooldown ) {
				return false;
			}
			/* Only stamped when a cooldown is in force: with the default of 0
			 * this would be a database write on every page view. */
			update_user_meta( $user_id, self::UMETA_SHOWN, time() );
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public static function ajax_prompt_guest(): void {
		wp_send_json_success( array( 'due' => false ) );
	}

	public static function ajax_prompt(): void {
		$user_id = get_current_user_id();
		nocache_headers();

		if ( $user_id <= 0 ) {
			wp_send_json_success( array( 'due' => false ) );
		}

		$user = get_userdata( $user_id );
		$name = $user instanceof WP_User ? (string) $user->first_name : '';
		if ( '' === trim( $name ) && $user instanceof WP_User ) {
			$name = (string) $user->display_name;
		}

		/* RC51.30: nonce + name are always returned for logged-in users so the
		 * "LAISSER UN AVIS" button can open the form on demand. */
		$on_thanks = ! empty( $_GET['thanks'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$due       = self::prompt_is_due( $user_id, (bool) $on_thanks );

		/* RC66: a completed order queued a product review invitation (user
		 * meta, written at order completion — no query here). The popup opens
		 * on that product, so the review lands where Google reads it. */
		$due_product = null;
		$due_pid     = absint( get_user_meta( $user_id, self::UMETA_DUE_PRODUCT, true ) );
		if ( $due_pid > 0 ) {
			$due_post = get_post( $due_pid );
			if ( $due_post instanceof WP_Post && 'product' === $due_post->post_type && 'publish' === $due_post->post_status ) {
				$due_product = array( 'id' => $due_pid, 'name' => sanitize_text_field( (string) $due_post->post_title ) );
				$due         = true;
			} else {
				delete_user_meta( $user_id, self::UMETA_DUE_PRODUCT );
			}
		}

		wp_send_json_success(
			array(
				'due'      => $due,
				'done'     => '' !== (string) get_user_meta( $user_id, self::UMETA_DONE, true ),
				'name'     => sanitize_text_field( $name ),
				'nonce'    => wp_create_nonce( 'delicat_builder_v9_review' ),
				'max'      => self::MAX_QUOTE_LEN,
				'product'  => $due_product,
			)
		);
	}

	/**
	 * RC66: called when an order reaches "completed" (hooked in the loader so
	 * it runs in admin, gateway callbacks and cron alike). Picks the first
	 * product of the order the client has not reviewed and queues one
	 * invitation for it; the popup shows it on the next visit, fixed on that
	 * product. Nothing is sent from here — no email, no push.
	 */
	public static function order_completed( int $order_id ): void {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		if ( ! apply_filters( 'delicat_builder_v9_review_invite_after_order', true, $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
			return;
		}
		$user_id = absint( $order->get_customer_id() );
		if ( $user_id <= 0 ) {
			return;
		}
		$reviewed = self::reviewed_product_ids( $user_id );
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$pid = absint( $item->get_product_id() );
			if ( $pid <= 0 || in_array( $pid, $reviewed, true ) ) {
				continue;
			}
			$product_post = get_post( $pid );
			if ( ! $product_post instanceof WP_Post || 'product' !== $product_post->post_type || 'publish' !== $product_post->post_status ) {
				continue;
			}
			update_user_meta( $user_id, self::UMETA_DUE_PRODUCT, $pid );
			delete_user_meta( $user_id, self::UMETA_SNOOZE );
			return;
		}
	}

	/**
	 * RC65: the client's purchases and already-reviewed products for the
	 * popup's product select. RC63 returned these from the prompt endpoint,
	 * which runs on every page view of every signed-in client — an order
	 * query per page view. Now fetched once per session, only when the
	 * popup actually opens.
	 */
	public static function ajax_products(): void {
		$user_id = get_current_user_id();
		nocache_headers();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Connecte-toi pour laisser un avis.', 'delicat-builder-v9' ) ), 401 );
		}
		if ( ! check_ajax_referer( 'delicat_builder_v9_review', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expirée. Recharge la page et réessaie.', 'delicat-builder-v9' ) ), 403 );
		}
		wp_send_json_success(
			array(
				'products' => self::purchased_products( $user_id ),
				'reviewed' => self::reviewed_product_ids( $user_id ),
			)
		);
	}

	/**
	 * RC63: products this client has actually bought (completed / processing
	 * orders, newest first, parents of variations), for the popup's select.
	 */
	public static function purchased_products( int $user_id, int $limit = 8 ): array {
		if ( $user_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		try {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user_id,
					'status'      => array( 'wc-completed', 'wc-processing' ),
					'limit'       => 12,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'objects',
				)
			);
			foreach ( (array) $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
					continue;
				}
				foreach ( $order->get_items() as $item ) {
					if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
						continue;
					}
					$pid = absint( $item->get_product_id() );
					if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
						continue;
					}
					$product_post = get_post( $pid );
					if ( ! $product_post instanceof WP_Post || 'product' !== $product_post->post_type || 'publish' !== $product_post->post_status ) {
						continue;
					}
					$seen[ $pid ] = true;
					$out[]        = array(
						'id'   => $pid,
						'name' => sanitize_text_field( (string) $product_post->post_title ),
					);
					if ( count( $out ) >= $limit ) {
						break 2;
					}
				}
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
		return $out;
	}

	/** RC63: product ids this client already reviewed (any status), so the popup does not offer them twice. */
	public static function reviewed_product_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$ids = array();
		try {
			$comments = get_comments(
				array(
					'user_id'   => $user_id,
					'post_type' => 'product',
					'type'      => 'review',
					'status'    => 'all',
					'number'    => 50,
				)
			);
			foreach ( (array) $comments as $comment ) {
				if ( $comment instanceof WP_Comment ) {
					$ids[ absint( $comment->comment_post_ID ) ] = true;
				}
			}
		} catch ( Throwable $error ) {
			unset( $error );
		}
		return array_values( array_map( 'intval', array_keys( $ids ) ) );
	}

	public static function ajax_snooze(): void {
		$user_id = get_current_user_id();
		/* RC51.29: writable endpoint — CSRF nonce required (same token as submit,
		 * delivered by the per-user prompt response, never printed in cached HTML). */
		if ( $user_id > 0 && check_ajax_referer( 'delicat_builder_v9_review', 'nonce', false ) ) {
			delete_user_meta( $user_id, self::UMETA_DUE_PRODUCT ); // RC66: one invitation per order, never a nag.
			update_user_meta( $user_id, self::UMETA_SNOOZE, time() + self::SNOOZE_SECONDS );
			/* RC43: the seven-day snooze no longer silences the invitation —
			 * only an actual review does. Dismissing still starts the cooldown
			 * for a boutique that configured one. */
			update_user_meta( $user_id, self::UMETA_SHOWN, time() );
			wp_send_json_success( array( 'snoozed' => true ) );
		}
		wp_send_json_error( array( 'snoozed' => false ), 403 );
	}

	public static function ajax_submit(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Connecte-toi pour laisser un avis.', 'delicat-builder-v9' ) ), 401 );
		}
		if ( ! check_ajax_referer( 'delicat_builder_v9_review', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expirée. Recharge la page et réessaie.', 'delicat-builder-v9' ) ), 403 );
		}

		/* RC63: a review can target one WooCommerce product. It is stored as a
		 * genuine product review (Products → Reviews), verified when the client
		 * bought it, so it feeds the product's star rating and the Product
		 * structured data Google reads. product_id 0 keeps the site-wide path. */
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( $product_id > 0 ) {
			$product_post = get_post( $product_id );
			if ( ! $product_post instanceof WP_Post || 'product' !== $product_post->post_type || 'publish' !== $product_post->post_status ) {
				wp_send_json_error( array( 'message' => __( 'Produit introuvable. Recharge la page et réessaie.', 'delicat-builder-v9' ) ), 404 );
			}
		}

		/* RC51.29: idempotence — a nonce replay after a successful submission
		 * must not create duplicate pending reviews. RC63: one review per
		 * product; the site-wide review keeps the one-per-client rule. */
		if ( $product_id > 0 ) {
			if ( in_array( $product_id, self::reviewed_product_ids( $user_id ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Tu as déjà noté ce produit. Mèsi anpil !', 'delicat-builder-v9' ) ), 409 );
			}
		} elseif ( '' !== (string) get_user_meta( $user_id, self::UMETA_DONE, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Ton avis a déjà été reçu. Mèsi anpil !', 'delicat-builder-v9' ) ), 409 );
		}

		/* One submission per user per 12 hours (per product for product
		 * reviews). The slot is reserved before the insert so two parallel
		 * requests cannot both pass the check. */
		$rl_key = 'dlc_rev_rl_' . $user_id . ( $product_id > 0 ? '_' . $product_id : '' );
		if ( false !== get_transient( $rl_key ) ) {
			wp_send_json_error( array( 'message' => __( 'Ton avis a déjà été reçu. Mèsi anpil !', 'delicat-builder-v9' ) ), 429 );
		}
		set_transient( $rl_key, 1, 12 * HOUR_IN_SECONDS );

		$rating = isset( $_POST['rating'] ) ? absint( wp_unslash( $_POST['rating'] ) ) : 0;
		$quote  = isset( $_POST['quote'] ) ? sanitize_textarea_field( wp_unslash( $_POST['quote'] ) ) : '';
		$city   = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '';
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		$rating = max( 1, min( 5, $rating ) );
		$quote  = trim( preg_replace( '/\s+/u', ' ', $quote ) );
		if ( function_exists( 'mb_substr' ) ) {
			$quote = function_exists( 'mb_substr' ) ? mb_substr( $quote, 0, self::MAX_QUOTE_LEN ) : substr( $quote, 0, self::MAX_QUOTE_LEN );
			$city  = function_exists( 'mb_substr' ) ? mb_substr( trim( $city ), 0, 40 ) : substr( trim( $city ), 0, 40 );
			$name  = function_exists( 'mb_substr' ) ? mb_substr( trim( $name ), 0, 40 ) : substr( trim( $name ), 0, 40 );
		} else {
			$quote = substr( $quote, 0, self::MAX_QUOTE_LEN );
			$city  = substr( trim( $city ), 0, 40 );
			$name  = substr( trim( $name ), 0, 40 );
		}

		if ( '' === $quote || strlen( $quote ) < 8 ) {
			wp_send_json_error( array( 'message' => __( 'Écris quelques mots sur ton expérience.', 'delicat-builder-v9' ) ), 400 );
		}
		$user = get_userdata( $user_id );
		if ( '' === $name ) {
			$name = $user instanceof WP_User ? (string) $user->display_name : __( 'Client Delicat', 'delicat-builder-v9' );
		}

		if ( $product_id > 0 ) {
			$email    = $user instanceof WP_User ? (string) $user->user_email : '';
			$verified = function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $email, $user_id, $product_id );
			if ( ! $verified && 'yes' === (string) get_option( 'woocommerce_review_rating_verification_required', 'no' ) ) {
				delete_transient( $rl_key );
				wp_send_json_error( array( 'message' => __( 'Seuls les acheteurs de ce produit peuvent le noter. Commande-le d’abord.', 'delicat-builder-v9' ) ), 403 );
			}
			$comment_id = wp_insert_comment(
				array(
					'comment_post_ID'      => $product_id,
					'comment_author'       => $name,
					'comment_author_email' => $email,
					'comment_author_url'   => '',
					'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
					'comment_agent'        => 'delicat-builder-v9/review-popup',
					'comment_content'      => $quote,
					'comment_type'         => 'review',
					'comment_parent'       => 0,
					'comment_approved'     => 0,
					'user_id'              => $user_id,
					'comment_date'         => current_time( 'mysql' ),
					'comment_date_gmt'     => current_time( 'mysql', 1 ),
				)
			);
			if ( ! $comment_id ) {
				delete_transient( $rl_key );
				wp_send_json_error( array( 'message' => __( 'Impossible d’enregistrer l’avis. Réessaie plus tard.', 'delicat-builder-v9' ) ), 500 );
			}
			add_comment_meta( (int) $comment_id, 'rating', $rating, true );
			add_comment_meta( (int) $comment_id, 'verified', $verified ? 1 : 0, true );
			add_comment_meta( (int) $comment_id, self::CMETA_CITY, $city, true );
			add_comment_meta( (int) $comment_id, self::CMETA_SOURCE, 'popup', true );
			update_user_meta( $user_id, self::UMETA_DONE, time() );
			delete_user_meta( $user_id, self::UMETA_SNOOZE );
			delete_user_meta( $user_id, self::UMETA_DUE_PRODUCT );
			if ( function_exists( 'wp_new_comment_notify_moderator' ) ) {
				wp_new_comment_notify_moderator( (int) $comment_id );
			}

			do_action( 'delicat_builder_v9_product_review_submitted', (int) $comment_id, $product_id, $user_id );

			wp_send_json_success(
				array(
					'message'    => __( 'Mèsi anpil. Ton avis sera publié après validation.', 'delicat-builder-v9' ),
					'product_id' => $product_id,
				)
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::CPT,
				'post_status'  => 'pending',
				'post_title'   => $name,
				'post_content' => $quote,
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			delete_transient( $rl_key ); // Release the reserved slot so the client can retry.
			wp_send_json_error( array( 'message' => __( 'Impossible d’enregistrer l’avis. Réessaie plus tard.', 'delicat-builder-v9' ) ), 500 );
		}

		update_post_meta( $post_id, self::META_RATING, $rating );
		update_post_meta( $post_id, self::META_CITY, $city );
		update_post_meta( $post_id, self::META_USER, $user_id );
		update_user_meta( $user_id, self::UMETA_DONE, time() );
		delete_user_meta( $user_id, self::UMETA_SNOOZE );

		do_action( 'delicat_builder_v9_review_submitted', (int) $post_id, $user_id );

		wp_send_json_success(
			array( 'message' => __( 'Mèsi anpil. Ton avis sera publié après validation.', 'delicat-builder-v9' ) )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Frontend assets                                                     */
	/* ------------------------------------------------------------------ */

	public static function enqueue(): void {
		if (
			is_admin()
			|| ! class_exists( 'Delicat_Builder_V9_Core', false )
			|| ! Delicat_Builder_V9_Core::is_enabled()
			|| Delicat_Builder_V9_Core::is_safe_mode()
		) {
			return;
		}

		$account = function_exists( 'is_account_page' ) && is_account_page();
		$thanks  = function_exists( 'is_order_received_page' ) && is_order_received_page();
		/* Same filter as the runtime router's surface test: widening one
		 * without the other would load the module and then refuse to ship its
		 * assets, which is exactly the failure this release fixes. */
		/* RC63: product pages too — the native product page carries the
		 * reviews block and its "Laisser un avis" button. RC65: only when
		 * that block is actually on the page (native engine active for this
		 * product and the Avis section switched on); other product pages
		 * have no button, so they ship no popup assets. */
		$product = function_exists( 'is_product' ) && is_product();
		if ( $product ) {
			$product = class_exists( 'Delicat_Builder_V9_Native_Product', false )
				&& is_callable( array( 'Delicat_Builder_V9_Native_Product', 'is_active_product' ) )
				&& Delicat_Builder_V9_Native_Product::is_active_product( absint( get_queried_object_id() ) )
				&& ! empty( Delicat_Builder_V9_Native_Product::settings()['show_reviews'] );
		}
		if ( ! apply_filters( 'delicat_builder_v9_reviews_surface', is_front_page() || $account || $thanks || $product ) ) {
			return;
		}

		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/reviews.css';
		$js  = DELICAT_BUILDER_V9_DIR . 'assets/js/reviews.js';
		if ( ! is_file( $css ) || ! is_file( $js ) ) {
			return;
		}

		/* RC51.31: guests load the popup too, so "LAISSER UN AVIS" always opens
		 * the review flow (guests see a login step). Anonymous HTML stays
		 * byte-identical for every guest — cache-safe. */
		$account_url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		if ( '' === $account_url ) {
			$account_url = wp_login_url( home_url( '/' ) );
		}

		wp_enqueue_style(
			'delicat-builder-v9-reviews',
			DELICAT_BUILDER_V9_URL . 'assets/css/reviews.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_script(
			'delicat-builder-v9-reviews',
			DELICAT_BUILDER_V9_URL . 'assets/js/reviews.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script(
			'delicat-builder-v9-reviews',
			'window.DelicatReviewsConfig=' . wp_json_encode(
				array(
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'loggedIn'   => is_user_logged_in(),
					'accountUrl' => esc_url_raw( $account_url ),
					'thanks'     => (bool) $thanks,
					/* RC63: the product on a product page — static per URL, cache-safe. */
					'productId'   => $product ? absint( get_queried_object_id() ) : 0,
					'productName' => $product ? sanitize_text_field( (string) get_the_title( absint( get_queried_object_id() ) ) ) : '',
				)
			) . ';',
			'before'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Cache purge on approval                                             */
	/* ------------------------------------------------------------------ */

	public static function maybe_purge_on_approval( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof WP_Post || self::CPT !== $post->post_type ) {
			return;
		}
		$was_public = ( 'publish' === $old_status );
		$is_public  = ( 'publish' === $new_status );
		if ( $was_public === $is_public ) {
			return; // Purge only when a review enters or leaves the approved set.
		}
		self::flush_rail_cache();

		$front_id = absint( get_option( 'page_on_front' ) );
		if ( $front_id > 0 && class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'purge_page' ) ) ) {
			Delicat_Builder_V9_Cache::purge_page( $front_id );
		}
		do_action( 'litespeed_purge_url', home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin moderation UI                                                 */
	/* ------------------------------------------------------------------ */

	public static function admin_columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['dlc_rating'] = __( 'Note', 'delicat-builder-v9' );
				$out['dlc_city']   = __( 'Ville', 'delicat-builder-v9' );
				$out['dlc_user']   = __( 'Client', 'delicat-builder-v9' );
			}
		}
		return $out;
	}

	public static function admin_column_value( string $column, int $post_id ): void {
		if ( 'dlc_rating' === $column ) {
			$rating = max( 1, min( 5, absint( get_post_meta( $post_id, self::META_RATING, true ) ) ) );
			echo '<span style="color:#e7a600;letter-spacing:.06em">' . esc_html( str_repeat( '★', $rating ) ) . '</span>';
			return;
		}
		if ( 'dlc_city' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, self::META_CITY, true ) );
			return;
		}
		if ( 'dlc_user' === $column ) {
			$user = get_userdata( absint( get_post_meta( $post_id, self::META_USER, true ) ) );
			echo $user instanceof WP_User ? esc_html( $user->user_login ) : '—';
		}
	}

	public static function register_meta_box(): void {
		add_meta_box(
			'delicat-review-details',
			__( 'Détails de l’avis', 'delicat-builder-v9' ),
			array( __CLASS__, 'render_meta_box' ),
			self::CPT,
			'side'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		$rating = max( 1, min( 5, absint( get_post_meta( $post->ID, self::META_RATING, true ) ) ) );
		$city   = (string) get_post_meta( $post->ID, self::META_CITY, true );
		$user   = get_userdata( absint( get_post_meta( $post->ID, self::META_USER, true ) ) );
		echo '<p><strong>' . esc_html__( 'Note :', 'delicat-builder-v9' ) . '</strong> <span style="color:#e7a600">' . esc_html( str_repeat( '★', $rating ) ) . '</span> (' . esc_html( (string) $rating ) . '/5)</p>';
		echo '<p><strong>' . esc_html__( 'Ville :', 'delicat-builder-v9' ) . '</strong> ' . esc_html( '' !== $city ? $city : '—' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Client :', 'delicat-builder-v9' ) . '</strong> ' . esc_html( $user instanceof WP_User ? $user->user_login : '—' ) . '</p>';
		echo '<p style="color:#666">' . esc_html__( 'Publier cet avis l’affiche dans « Ils nous font confiance » sur la page d’accueil.', 'delicat-builder-v9' ) . '</p>';
	}
}
