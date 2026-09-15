<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one badge engine.
 *
 * -----------------------------------------------------------------------------
 * What this replaces
 * -----------------------------------------------------------------------------
 * Badges were decided in four places that did not know about each other:
 *
 *   - Badge_Engine::resolve()        a corner label, from a category, a tag, the
 *                                    sale state, the stock state, or free text
 *   - Badge_Engine::resolve_status() a second label, from the sale state again,
 *                                    the sales count, or the publish date
 *   - native-product.php             "Livraison instantanee", drawn with an
 *                                    emoji, on its own markup and its own class
 *   - the archive and page builders  two more badge classes of their own
 *
 * Nothing tied them together, so the same product could carry "Promo" in one
 * corner from one engine and "PROMO" in another from the other, and the product
 * page used a fourth style with an emoji in it. An emoji is not an icon: it is a
 * font glyph that renders differently on every phone, ignores the badge's
 * colour, and on Android often arrives as a coloured bitmap that clashes with
 * everything around it.
 *
 * V10's rule, applied here: one engine decides, one set of drawn SVG icons, and
 * the storefront cannot produce a badge this class did not author.
 *
 * -----------------------------------------------------------------------------
 * What a product gets
 * -----------------------------------------------------------------------------
 * At most two badges, in fixed slots:
 *
 *   TOPIC   from the product's own WooCommerce tags, matched against the six
 *           the merchant sells under - Jeux, Finance, Gift Card, Reseaux
 *           Sociaux, Streaming, Mobile. Says what kind of thing this is.
 *
 *   STATUS  #1, then Promo, then Top Vente. Says why to look at it now.
 *           Rarest first: a product that is both the best seller and on sale
 *           shows "#1", because that is the rarer claim.
 *
 * Every value comes from WooCommerce: the tags are the merchant's own taxonomy,
 * "Promo" is is_on_sale(), "Top Vente" and "#1" are get_total_sales(). Nothing
 * is stored, nothing is computed twice, and there is no admin screen that can
 * put arbitrary HTML in a badge.
 */
final class Delicat_Builder_V9_Badges {

	/** Legacy modes the Builder's saved settings still use. */
	public const MODES = array( 'auto', 'topic', 'tag', 'category', 'sale', 'stock', 'custom', 'off' );

	/** @var array<string,array> */
	private static array $cache = array();

	/** @var int|null */
	private static ?int $best_seller = null;

	/* =====================================================================
	 * The icons
	 * ===================================================================== */

	/**
	 * One drawn set: 24x24, 1.75 stroke, currentColor, no fills.
	 *
	 * Inline rather than a sprite or an icon font, because a badge is small,
	 * appears many times per screen, and must not be able to arrive late and
	 * shift the card. currentColor means one icon works on every badge tone
	 * and in both themes without a second asset.
	 *
	 * @var array<string,string>
	 */
	private const ICONS = array(
		'gamepad' => '<path d="M7.5 11h-3M6 9.5v3"/><circle cx="16" cy="10.5" r=".9"/><circle cx="18.4" cy="13" r=".9"/><path d="M8.2 7h7.6a4.2 4.2 0 0 1 4.1 3.3l1 4.6A2.6 2.6 0 0 1 18.4 18c-1 0-1.6-.5-2.2-1.1l-.9-.9H8.7l-.9.9c-.6.6-1.2 1.1-2.2 1.1a2.6 2.6 0 0 1-2.5-3.1l1-4.6A4.2 4.2 0 0 1 8.2 7Z"/>',
		'wallet'  => '<path d="M3.5 8A2.5 2.5 0 0 1 6 5.5h11A1.5 1.5 0 0 1 18.5 7v1"/><rect x="3.5" y="8" width="17" height="11" rx="2.5"/><circle cx="16.2" cy="13.5" r="1.15"/>',
		'gift'    => '<rect x="3.5" y="9.5" width="17" height="10.5" rx="2"/><path d="M3.5 13.8h17M12 9.5V20"/><path d="M12 9.5S9.7 4.4 7.4 5.4 9.2 9.5 12 9.5Zm0 0s2.3-5.1 4.6-4.1S14.8 9.5 12 9.5Z"/>',
		'share'   => '<circle cx="17.5" cy="6.5" r="2.4"/><circle cx="6.5" cy="12" r="2.4"/><circle cx="17.5" cy="17.5" r="2.4"/><path d="m8.7 10.9 6.6-3.3M8.7 13.1l6.6 3.3"/>',
		'play'    => '<rect x="2.8" y="5" width="18.4" height="13" rx="2.6"/><path d="m10.4 9.6 4.4 2.4-4.4 2.4Z"/>',
		'phone'   => '<rect x="6.5" y="2.8" width="11" height="18.4" rx="2.6"/><path d="M10.6 5.4h2.8"/><path d="M11 18.6h2"/>',

		'crown'   => '<path d="m3.6 7.8 3.2 2.6L12 5l5.2 5.4 3.2-2.6-1.6 9.4H5.2Z"/><path d="M5.6 19.6h12.8"/>',
		'tag'     => '<path d="M3.6 11.3V5.1a1.3 1.3 0 0 1 1.3-1.3h6.2a1.3 1.3 0 0 1 .9.4l8 8a1.3 1.3 0 0 1 0 1.8l-6.2 6.2a1.3 1.3 0 0 1-1.8 0l-8-8a1.3 1.3 0 0 1-.4-.9Z"/><circle cx="7.9" cy="7.9" r="1.15"/>',
		'flame'   => '<path d="M12 3s.9 2.8-1.1 4.8c-1.7 1.7-3.4 3-3.4 5.6a4.5 4.5 0 0 0 9 0c0-1.6-.7-2.8-1.6-3.8-.3 1-1 1.6-1.7 1.6.8-2.6-1.2-6.2-1.2-8.2Z"/>',
		/* Instant delivery had the flame too, which already means Top Vente.
		 * Two meanings on one glyph is not an icon set, it is a coincidence. */
		'bolt'    => '<path d="M13.2 2.5 5.4 13.1h5.6L10.8 21.5l7.8-10.6H13Z"/>',

		/* The favourite marker. It was four literal characters - the heart, the
		 * outline heart, the star and the lightning bolt - printed straight into
		 * every product card. Each of those renders as a different glyph on
		 * every phone, at a size the font decides rather than the design, and on
		 * Android several arrive as coloured bitmaps that ignore the card's
		 * palette entirely. Drawn, they are the same mark everywhere. */
		'heart'         => '<path d="M12 20.3 4.7 13.1a4.6 4.6 0 1 1 7.3-5.4 4.6 4.6 0 1 1 7.3 5.4Z"/>',
		'heart-outline' => '<path d="M12 20.3 4.7 13.1a4.6 4.6 0 1 1 7.3-5.4 4.6 4.6 0 1 1 7.3 5.4Z"/>',
		'star'          => '<path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 17l-5.3 2.8 1.1-5.9-4.3-4.1 5.9-.8Z"/>',
	);

	public static function icon( string $name, int $size = 14 ): string {
		if ( ! isset( self::ICONS[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg class="delicat-badge__icon" viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			max( 10, min( 32, $size ) ),
			self::ICONS[ $name ]
		);
	}

	/** @return string[] */
	public static function icon_names(): array {
		return array_keys( self::ICONS );
	}

	/**
	 * The favourite marker, drawn.
	 *
	 * The filled and outline hearts share a path and differ only in whether it
	 * is filled - one shape, two states, rather than two glyphs that a font may
	 * or may not draw at the same weight.
	 */
	public static function heart( string $icon = 'heart', int $size = 18 ): string {
		$name = in_array( $icon, array( 'heart', 'heart_outline', 'star', 'bolt' ), true ) ? $icon : 'heart';
		$name = 'heart_outline' === $name ? 'heart-outline' : $name;

		$svg = self::icon( $name, $size );
		if ( '' === $svg ) {
			return '';
		}

		/* Filled for the solid heart and the star; outline for the rest. */
		if ( in_array( $name, array( 'heart', 'star' ), true ) ) {
			$svg = str_replace( 'fill="none"', 'fill="currentColor"', $svg );
		}

		return str_replace( 'delicat-badge__icon', 'delicat-heart__icon', $svg );
	}

	/**
	 * The interactive favourite mark: the same shape, drawn twice.
	 *
	 * The outline is what an unfavourited product shows; the fill sits on top
	 * of it at scale(0) and springs out when the shopper taps. Two paths and
	 * no second request, so the "liked" state is a transform on a shape that
	 * is already on the card rather than a new icon that has to arrive.
	 *
	 * @param string $icon One of heart, heart_outline, star, bolt.
	 */
	public static function favorite_mark( string $icon = 'heart' ): string {
		$name = in_array( $icon, array( 'heart', 'heart_outline', 'star', 'bolt' ), true ) ? $icon : 'heart';
		$name = 'heart_outline' === $name ? 'heart' : $name;
		$path = self::ICONS[ $name ] ?? self::ICONS['heart'];

		return '<span class="delicat-like" aria-hidden="true">'
			. '<svg class="delicat-like__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
			. str_replace( '<path ', '<path class="delicat-like__outline" ', $path )
			. str_replace( '<path ', '<path class="delicat-like__fill" ', $path )
			. '</svg></span>';
	}

	/* =====================================================================
	 * The topics
	 * ===================================================================== */

	/**
	 * The six kinds of thing this store sells, and how to recognise them.
	 *
	 * Matched against the product's WooCommerce tag slugs AND names, because a
	 * merchant types "Réseaux Sociaux" and WordPress stores "reseaux-sociaux"
	 * - or "resaux-sociaux" if the accent was dropped when the tag was created,
	 * which is how it is spelled on this store. Both spellings match, and so do
	 * the obvious English equivalents, so a tag added later in either language
	 * still finds its badge.
	 *
	 * @return array<string,array{label:string,icon:string,match:string[]}>
	 */
	public static function topics(): array {
		$topics = array(
			'jeux' => array(
				'label' => __( 'Jeux', 'delicat-builder-v9' ),
				'icon'  => 'gamepad',
				'match' => array( 'jeux', 'jeu', 'gaming', 'game', 'games', 'jeux-video', 'free-fire', 'pubg' ),
			),
			'finance' => array(
				'label' => __( 'Finance', 'delicat-builder-v9' ),
				'icon'  => 'wallet',
				'match' => array( 'finance', 'financier', 'moncash', 'mon-cash', 'transfert', 'paiement', 'crypto', 'usdt' ),
			),
			'gift-card' => array(
				'label' => __( 'Gift Card', 'delicat-builder-v9' ),
				'icon'  => 'gift',
				'match' => array( 'gift-card', 'giftcard', 'gift-cards', 'carte-cadeau', 'cartes-cadeaux', 'carte-cadeaux' ),
			),
			'reseaux-sociaux' => array(
				'label' => __( 'Réseaux Sociaux', 'delicat-builder-v9' ),
				'icon'  => 'share',
				'match' => array( 'reseaux-sociaux', 'resaux-sociaux', 'reseaux', 'resaux', 'social', 'socials', 'social-media', 'instagram', 'tiktok', 'facebook' ),
			),
			'streaming' => array(
				'label' => __( 'Streaming', 'delicat-builder-v9' ),
				'icon'  => 'play',
				'match' => array( 'streaming', 'stream', 'netflix', 'spotify', 'video', 'abonnement' ),
			),
			'mobile' => array(
				'label' => __( 'Mobile', 'delicat-builder-v9' ),
				'icon'  => 'phone',
				'match' => array( 'mobile', 'recharge-mobile', 'telephone', 'telephonie', 'minutes', 'forfait', 'natcom', 'digicel' ),
			),
		);

		/**
		 * The topic badges and what they match.
		 *
		 * @param array<string,array> $topics
		 */
		$topics = apply_filters( 'delicat_builder_v9_badge_topics', $topics );

		return is_array( $topics ) ? $topics : array();
	}

	/**
	 * The topic badge for a product, from its own tags.
	 *
	 * Tag order decides: WooCommerce returns them in term order, so a product
	 * tagged both "Jeux" and "Mobile" shows whichever the merchant listed
	 * first. Categories are deliberately NOT consulted - a category is how the
	 * catalogue is organised, a tag is what the merchant says this product is,
	 * and mixing the two is how the old engine ended up labelling a Free Fire
	 * top-up "Delicat Digital".
	 *
	 * @return array{key:string,label:string,icon:string,tone:string}|array{}
	 */
	public static function topic( WC_Product $product ): array {
		$terms = get_the_terms( $product->get_id(), 'product_tag' );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$topics = self::topics();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$slug = self::normalise( (string) $term->slug );
			$name = self::normalise( (string) $term->name );

			foreach ( $topics as $key => $topic ) {
				foreach ( (array) ( $topic['match'] ?? array() ) as $needle ) {
					$needle = self::normalise( (string) $needle );
					if ( '' === $needle ) {
						continue;
					}
					if ( $slug === $needle || $name === $needle ) {
						return array(
							'key'   => sanitize_html_class( (string) $key ),
							'label' => (string) ( $topic['label'] ?? $term->name ),
							'icon'  => (string) ( $topic['icon'] ?? '' ),
							'tone'  => 'topic',
						);
					}
				}
			}
		}

		return array();
	}

	/**
	 * Accents, case and separators removed, so "Réseaux Sociaux",
	 * "reseaux_sociaux" and "Resaux-Sociaux" are one thing.
	 */
	private static function normalise( string $value ): string {
		$value = strtolower( trim( $value ) );

		if ( function_exists( 'remove_accents' ) ) {
			$value = remove_accents( $value );
		}

		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}

	/* =====================================================================
	 * The status
	 * ===================================================================== */

	/**
	 * #1, then Promo, then Top Vente. One of them, never two.
	 *
	 * Ordered rarest first on purpose. A product that is the store's best
	 * seller AND on sale shows "#1": every shop has promotions, only one
	 * product is number one, and stacking both badges on one card is how a
	 * storefront starts looking like a discount bin.
	 *
	 * @param int $sales_min How many sales make a "Top Vente".
	 * @return array{key:string,label:string,icon:string,tone:string}|array{}
	 */
	public static function status( WC_Product $product, int $sales_min = 10 ): array {
		$sales_min = max( 1, min( 10000, $sales_min ) );

		if ( self::is_best_seller( $product ) ) {
			return array(
				'key'   => 'rank-1',
				'label' => __( '#1', 'delicat-builder-v9' ),
				'icon'  => 'crown',
				'tone'  => 'rank',
			);
		}

		if ( $product->is_on_sale() ) {
			return array(
				'key'   => 'promo',
				'label' => __( 'Promo', 'delicat-builder-v9' ),
				'icon'  => 'tag',
				'tone'  => 'promo',
			);
		}

		if ( absint( $product->get_total_sales() ) >= $sales_min ) {
			return array(
				'key'   => 'top-vente',
				'label' => __( 'Top Vente', 'delicat-builder-v9' ),
				'icon'  => 'flame',
				'tone'  => 'top',
			);
		}

		return array();
	}

	/**
	 * Is this the store's single best-selling product?
	 *
	 * One indexed query per hour, for the whole store, cached in a transient
	 * and recomputed when an order completes. total_sales is a WooCommerce
	 * meta key it maintains itself, so this reads WooCommerce's own number
	 * rather than counting orders.
	 */
	public static function is_best_seller( WC_Product $product ): bool {
		if ( null === self::$best_seller ) {
			$cached = get_transient( 'delicat_builder_v9_best_seller' );

			if ( false === $cached ) {
				$cached = self::query_best_seller();
				set_transient( 'delicat_builder_v9_best_seller', $cached, HOUR_IN_SECONDS );
			}

			self::$best_seller = absint( $cached );
		}

		/* A store with no sales at all has no number one, and every product
		 * would otherwise match id 0. */
		return self::$best_seller > 0 && self::$best_seller === (int) $product->get_id();
	}

	private static function query_best_seller(): int {
		global $wpdb;

		try {
			$id = $wpdb->get_var(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'total_sales'
				 WHERE p.post_type = 'product' AND p.post_status = 'publish'
				 ORDER BY CAST(m.meta_value AS UNSIGNED) DESC, p.ID ASC
				 LIMIT 1"
			);
		} catch ( Throwable $error ) {
			unset( $error );
			return 0;
		}

		$id = absint( $id );
		if ( $id <= 0 ) {
			return 0;
		}

		/* A single sale does not make a number one. */
		$sales = absint( get_post_meta( $id, 'total_sales', true ) );
		return $sales >= 3 ? $id : 0;
	}

	public static function flush_best_seller(): void {
		self::$best_seller = null;
		delete_transient( 'delicat_builder_v9_best_seller' );
	}

	/* =====================================================================
	 * Rendering
	 * ===================================================================== */

	/**
	 * Both badges for a product, resolved once per request.
	 *
	 * @param array{sales_min?:int,mode?:string} $options
	 * @return array{topic:array,status:array}
	 */
	public static function for_product( WC_Product $product, array $options = array() ): array {
		$mode      = self::sanitize_mode( (string) ( $options['mode'] ?? 'auto' ) );
		$sales_min = absint( $options['sales_min'] ?? 10 );
		$key       = $product->get_id() . '|' . $mode . '|' . $sales_min;

		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		$result = array( 'topic' => array(), 'status' => array() );

		if ( 'off' !== $mode ) {
			$result['topic']  = self::topic( $product );
			$result['status'] = self::status( $product, $sales_min ?: 10 );
		}

		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * One badge, as markup.
	 *
	 * The label is escaped; the icon comes from the fixed table above and can
	 * only be one of nine strings this file contains. There is no path by which
	 * merchant input, product data or a setting reaches the markup unescaped,
	 * which is why the old engine's "custom" free-text mode is gone.
	 *
	 * @param array{key?:string,label?:string,icon?:string,tone?:string} $badge
	 */
	public static function render( array $badge, string $slot = 'topic' ): string {
		$label = trim( (string) ( $badge['label'] ?? '' ) );
		if ( '' === $label ) {
			return '';
		}

		$slot = 'status' === $slot ? 'status' : 'topic';
		$tone = sanitize_html_class( (string) ( $badge['tone'] ?? $slot ) );
		$key  = sanitize_html_class( (string) ( $badge['key'] ?? '' ) );

		return sprintf(
			'<span class="delicat-badge delicat-badge--%1$s delicat-badge--%2$s%3$s">%4$s<span class="delicat-badge__text">%5$s</span></span>',
			esc_attr( $slot ),
			esc_attr( $tone ),
			'' !== $key ? ' delicat-badge--' . esc_attr( $key ) : '',
			self::icon( (string) ( $badge['icon'] ?? '' ) ),
			esc_html( $label )
		);
	}

	/**
	 * "Livraison instantanée", for a digital product.
	 *
	 * Same engine, same markup, same drawn icon set - previously this was the
	 * one badge in the storefront built from an emoji and its own CSS class.
	 */
	public static function instant_delivery(): array {
		return array(
			'key'   => 'instant',
			'label' => __( 'Livraison instantanée', 'delicat-builder-v9' ),
			'icon'  => 'bolt',
			'tone'  => 'instant',
		);
	}

	/* =====================================================================
	 * Compatibility with saved Builder settings
	 * ===================================================================== */

	public static function sanitize_mode( $mode ): string {
		$mode = sanitize_key( (string) $mode );

		/* Settings saved by the old engine still name its modes. They all
		 * resolve to the one behaviour now; keeping them valid means an
		 * existing Builder page does not have to be re-saved. */
		$legacy = array( 'category' => 'auto', 'tag' => 'auto', 'sale' => 'auto', 'stock' => 'auto', 'custom' => 'auto' );
		$mode   = $legacy[ $mode ] ?? $mode;

		return in_array( $mode, array( 'auto', 'topic', 'off' ), true ) ? $mode : 'auto';
	}

	/**
	 * The icon set, for the cards the carousel builds in the browser.
	 *
	 * Printed from the same constant the server renders from, so a card built
	 * on the server and a card built in the browser are the same card, and
	 * carousel.js holds no icon of its own to fall out of date. Attached only
	 * when the carousel script is actually on the page.
	 */
	public static function print_icon_table(): void {
		if ( ! wp_script_is( 'delicat-builder-v9-carousel', 'enqueued' ) ) {
			return;
		}

		wp_add_inline_script(
			'delicat-builder-v9-carousel',
			'window.DelicatBadgeIcons=' . wp_json_encode( self::ICONS ) . ';',
			'before'
		);
	}

	public static function boot(): void {
		/* The number one changes when an order completes, not on a timer. */
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'flush_best_seller' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'flush_best_seller' ) );

		/* Late, so every module that might enqueue the carousel has done so. */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'print_icon_table' ), 100 );
	}
}

Delicat_Builder_V9_Badges::boot();
