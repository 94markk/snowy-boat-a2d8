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
		/* 9.11.0: ranking medal (#1, #2, #3). The disc's fill (gold, silver, bronze) comes from the stylesheet. */
		'medal'   => '<circle class="m-disc" cx="12" cy="9" r="6"/><path d="m8.5 14-1.5 8 5-3 5 3-1.5-8"/>',
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

		/* 9.12.0: the pack badges (Populaire, Meilleure offre, Nouveau…) and the delivery time were
		 * typed as emoji (« 🔥 Populaire », « ⚡ Livraison instantanée »). Same drawn set now. */
		'truck'   => '<path d="M2.8 6.6h10.6v9.6H2.8Z"/><path d="M13.4 9.6h4l3 3.3v3.3h-7"/><circle cx="7" cy="17.4" r="1.7"/><circle cx="16.9" cy="17.4" r="1.7"/>',
		'clock'   => '<circle cx="12" cy="12" r="8.6"/><path d="M12 7.4V12l3.1 2"/>',
		'award'   => '<circle cx="12" cy="9.2" r="5.6"/><path d="m9 14.2-1.6 7 4.6-2.6 4.6 2.6-1.6-7"/><path d="m10 9.3 1.4 1.4 2.8-2.8"/>',
		'sparkle' => '<path d="M11 3.6 12.7 9l5.4 1.7-5.4 1.7L11 17.8l-1.7-5.4-5.4-1.7L9.3 9Z"/><path d="M18.6 3.4v3.4M16.9 5.1h3.4"/>',
		'shield'  => '<path d="M12 3 4.6 6v5.6c0 4.5 3.1 8 7.4 9.4 4.3-1.4 7.4-4.9 7.4-9.4V6Z"/><path d="m9 12 2.1 2.1L15.3 10"/>',
		'rocket'  => '<path d="M14.6 4.2C17 3.4 20.3 3.7 20.3 3.7s.3 3.3-.5 5.7c-.9 2.6-4.4 5.9-6.5 7.4l-6.1-6.1c1.5-2.1 4.8-5.6 7.4-6.5Z"/><circle cx="15.3" cy="8.7" r="1.4"/><path d="M7.2 10.7 4 11.4l-1.2 2.4 3.6 1M13.3 16.8l-.7 3.2-2.4 1.2-1-3.6"/>',
		'check'   => '<circle cx="12" cy="12" r="8.6"/><path d="m8.2 12.2 2.6 2.6 5-5.1"/>',
		'ban'     => '<circle cx="12" cy="12" r="8.6"/><path d="m5.9 5.9 12.2 12.2"/>',
		'percent' => '<path d="m6.2 17.8 11.6-11.6"/><circle cx="7.6" cy="7.6" r="2.1"/><circle cx="16.4" cy="16.4" r="2.1"/>',
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

	/** 9.12.1: the drawn paths of one icon ('' when unknown), for cards the engine builds in the browser. */
	public static function icon_paths( string $name ): string {
		return self::ICONS[ $name ] ?? '';
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
			return self::rank( 1 );
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
	 * 9.11.0: a ranking badge - the medal for #1, #2 or #3 of a « Les plus achetés »
	 * rail (ordered by WooCommerce's own total_sales), or for the store's best seller.
	 *
	 * @return array{key:string,label:string,icon:string,tone:string}
	 */
	public static function rank( int $position ): array {
		$position = max( 1, min( 3, $position ) );
		return array(
			'key'   => 'rank-' . $position,
			/* 9.12.2: the pill shows « #1 » (the full words were cut on the cards); the full words are the
			 * accessible name and the tooltip. */
			'label' => '#' . $position,
			/* translators: %d: ranking position (1 to 3) */
			'title' => sprintf( __( 'Numéro %d des ventes', 'delicat-builder-v9' ), $position ),
			'icon'  => 'medal',
			'tone'  => 'rank',
		);
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

		$title = trim( (string) ( $badge['title'] ?? '' ) );
		return sprintf(
			'<span class="delicat-badge delicat-badge--%1$s delicat-badge--%2$s%3$s"%6$s>%4$s<span class="delicat-badge__text"%7$s>%5$s</span></span>',
			esc_attr( $slot ),
			esc_attr( $tone ),
			'' !== $key ? ' delicat-badge--' . esc_attr( $key ) : '',
			self::icon( (string) ( $badge['icon'] ?? '' ) ),
			esc_html( $label ),
			'' !== $title ? ' role="img" title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $title ) . '"' : '',
			'' !== $title ? ' aria-hidden="true"' : ''
		);
	}

	/* =====================================================================
	 * 9.12.0: pack badges (the swatch cards) and the delivery time option
	 * ===================================================================== */

	/** Product and variation meta: '' (inherit), 'instant', 'fifteen_min' or 'none'. */
	public const DELIVERY_META = '_dlc_delivery';

	/** Derived from the variations' saved delivery badges, for the product cards. */
	public const DELIVERY_AUTO_META = '_dlc_delivery_auto';

	/** The choices the owner sees (product, variation, bulk action, global default). */
	public static function delivery_choices(): array {
		return array(
			'instant'     => __( 'Livraison instantanée', 'delicat-builder-v9' ),
			'fifteen_min' => __( 'Dans environ 15 min', 'delicat-builder-v9' ),
			'none'        => __( 'Aucun badge', 'delicat-builder-v9' ),
		);
	}

	public static function sanitize_delivery( $value, bool $allow_inherit = true ): string {
		$value = sanitize_key( (string) $value );
		if ( $allow_inherit && '' === $value ) {
			return '';
		}
		return array_key_exists( $value, self::delivery_choices() ) ? $value : ( $allow_inherit ? '' : 'none' );
	}

	/**
	 * A delivery badge as data. Keys: the three choices above, plus the swatch presets saved before
	 * 9.12 (five_min, manual, custom text), so a pack keeps the badge it had.
	 *
	 * @return array{key:string,label:string,icon:string,tone:string}|array{}
	 */
	public static function delivery_badge( string $key, string $custom = '' ): array {
		switch ( $key ) {
			case 'instant':
				return array( 'key' => 'instant', 'label' => __( 'Livraison instantanée', 'delicat-builder-v9' ), 'icon' => 'bolt', 'tone' => 'instant' );
			case 'fifteen_min':
				return array( 'key' => 'fifteen-min', 'label' => __( 'Dans environ 15 min', 'delicat-builder-v9' ), 'icon' => 'truck', 'tone' => 'delay' );
			case 'five_min':
				return array( 'key' => 'five-min', 'label' => __( 'Dans environ 5 min', 'delicat-builder-v9' ), 'icon' => 'truck', 'tone' => 'delay' );
			case 'manual':
				return array( 'key' => 'manual', 'label' => __( 'Validation manuelle', 'delicat-builder-v9' ), 'icon' => 'clock', 'tone' => 'delay' );
			case 'custom':
				$label = self::strip_emoji( $custom );
				if ( '' === $label ) {
					return array();
				}
				$icon = self::emoji_icon( $custom );
				return array( 'key' => 'custom', 'label' => $label, 'icon' => '' !== $icon ? $icon : 'truck', 'tone' => 'delay' );
		}
		return array();
	}

	/** Global default (Swatch studio « Badges »): 'auto' = as each product already shows. */
	public static function delivery_default(): string {
		$saved = get_option( 'delicat_swatches_settings', array() );
		$value = is_array( $saved ) ? sanitize_key( (string) ( $saved['delivery_default'] ?? 'auto' ) ) : 'auto';
		return array_key_exists( $value, self::delivery_choices() ) ? $value : 'auto';
	}

	/**
	 * The product's own choice, else the global default; '' means « as before » (each pack's
	 * saved badge on the product page, the derived value on the cards).
	 */
	public static function product_delivery( int $product_id ): string {
		$own = self::sanitize_delivery( get_post_meta( $product_id, self::DELIVERY_META, true ) );
		if ( '' !== $own ) {
			return $own;
		}
		$default = self::delivery_default();
		return 'auto' === $default ? '' : $default;
	}

	/**
	 * The delivery value a product's packs share, from their saved swatch badges (before 9.12 the
	 * only place it existed). 'mixed' when they differ, 'none' when none carries one.
	 */
	public static function derive_delivery( int $product_id ): string {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return 'none';
		}
		$ids  = $product->is_type( 'variable' ) ? array_map( 'absint', $product->get_children() ) : array( $product_id );
		$seen = array();
		foreach ( $ids as $id ) {
			$own = self::sanitize_delivery( get_post_meta( $id, self::DELIVERY_META, true ) );
			if ( '' !== $own && $id !== $product_id ) {
				$seen[ $own ] = true;
				continue;
			}
			$preset = sanitize_key( (string) get_post_meta( $id, '_ddsw_delivery_preset', true ) );
			$text   = (string) get_post_meta( $id, '_ddsw_delivery_text', true );
			$key    = self::infer_delivery_key( $preset, $text );
			if ( '' !== $key ) {
				$seen[ $key ] = true;
			}
		}
		unset( $seen['none'] );
		if ( ! $seen ) {
			return 'none';
		}
		return 1 === count( $seen ) ? (string) array_key_first( $seen ) : 'mixed';
	}

	/** A saved swatch delivery preset or text, as a key ('custom' for free text). */
	public static function infer_delivery_key( string $preset, string $text ): string {
		$preset = sanitize_key( $preset );
		if ( in_array( $preset, array( 'instant', 'five_min', 'fifteen_min', 'manual', 'custom' ), true ) ) {
			return 'custom' === $preset ? self::infer_delivery_text( $text ) : $preset;
		}
		return self::infer_delivery_text( $text );
	}

	private static function infer_delivery_text( string $text ): string {
		$needle = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $text ) : $text );
		$needle = trim( self::strip_emoji( $needle ) );
		if ( '' === $needle ) {
			return '';
		}
		if ( false !== strpos( $needle, 'instant' ) ) {
			return 'instant';
		}
		if ( preg_match( '/\b15\s*min/', $needle ) ) {
			return 'fifteen_min';
		}
		if ( preg_match( '/\b5\s*min/', $needle ) ) {
			return 'five_min';
		}
		if ( false !== strpos( $needle, 'manuel' ) || false !== strpos( $needle, 'manual' ) ) {
			return 'manual';
		}
		return 'custom';
	}

	public static function refresh_delivery_auto( int $product_id ): void {
		if ( $product_id > 0 && 'product' === get_post_type( $product_id ) ) {
			update_post_meta( $product_id, self::DELIVERY_AUTO_META, self::derive_delivery( $product_id ) );
		}
	}

	/**
	 * The pack badge the owner chose for a variation (Populaire, Meilleur choix…), from its saved
	 * preset key or, for free text, from the words and the emoji it starts with. The emoji itself is
	 * never printed: it becomes the drawn icon. Stored keys and texts are not touched.
	 *
	 * @return array{key:string,label:string,icon:string,tone:string}|array{}
	 */
	public static function pack_badge( string $preset, string $text ): array {
		$map = array(
			'popular'     => array( __( 'Populaire', 'delicat-builder-v9' ), 'flame' ),
			'best_choice' => array( __( 'Meilleur choix', 'delicat-builder-v9' ), 'star' ),
			'best_offer'  => array( __( 'Meilleure offre', 'delicat-builder-v9' ), 'award' ),
			'best_value'  => array( __( 'Meilleure valeur', 'delicat-builder-v9' ), 'award' ),
			'promo'       => array( __( 'Promo', 'delicat-builder-v9' ), 'percent' ),
			'new'         => array( __( 'Nouveau', 'delicat-builder-v9' ), 'sparkle' ),
			'secure'      => array( __( 'Sécurisé', 'delicat-builder-v9' ), 'shield' ),
			'bonus'       => array( __( 'Bonus', 'delicat-builder-v9' ), 'gift' ),
			'fastest'     => array( __( 'Le + rapide', 'delicat-builder-v9' ), 'rocket' ),
		);
		$preset = sanitize_key( $preset );
		$clean  = self::strip_emoji( $text );
		if ( '' === $preset || 'custom' === $preset || ! isset( $map[ $preset ] ) ) {
			$needle = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $clean ) : $clean );
			$checks = array(
				'populaire' => 'popular', 'popular' => 'popular',
				'meilleur choix' => 'best_choice', 'best choice' => 'best_choice',
				'meilleure offre' => 'best_offer', 'meilleur offre' => 'best_offer', 'best offer' => 'best_offer',
				'meilleure valeur' => 'best_value', 'best value' => 'best_value',
				'promo' => 'promo', 'nouveau' => 'new', 'new' => 'new',
				'securise' => 'secure', 'secure' => 'secure', 'bonus' => 'bonus', 'le + rapide' => 'fastest', 'fastest' => 'fastest',
			);
			$preset = '';
			foreach ( $checks as $fragment => $key ) {
				if ( '' !== $needle && trim( $needle ) === $fragment ) {
					$preset = $key;
					break;
				}
			}
		}
		if ( isset( $map[ $preset ] ) ) {
			/* « Meilleur offre » typed by hand: the label is printed in correct French. */
			$label = 'best_offer' === $preset || '' === $clean ? $map[ $preset ][0] : $clean;
			return array( 'key' => str_replace( '_', '-', $preset ), 'label' => $label, 'icon' => $map[ $preset ][1], 'tone' => 'pack' );
		}
		if ( '' === $clean ) {
			return array();
		}
		$icon = self::emoji_icon( $text );
		if ( preg_match( '/^-?\s*\d{1,2}\s*%$/', $clean ) ) {
			$icon = 'percent';
		}
		return array( 'key' => 'custom', 'label' => $clean, 'icon' => '' !== $icon ? $icon : 'tag', 'tone' => 'pack' );
	}

	/** The « Recommandé » pack and the out-of-stock pack. */
	public static function recommended_badge( string $label ): array {
		$label = self::strip_emoji( $label );
		return array( 'key' => 'recommended', 'label' => '' !== $label ? $label : __( 'Recommandé', 'delicat-builder-v9' ), 'icon' => 'check', 'tone' => 'pack' );
	}

	public static function sold_out_badge(): array {
		return array( 'key' => 'sold-out', 'label' => __( 'Rupture', 'delicat-builder-v9' ), 'icon' => 'ban', 'tone' => 'pack' );
	}

	/** A pack badge as markup (the swatch classes it always had, plus the drawn icon). */
	public static function render_pack( array $badge, string $class ): string {
		$label = trim( (string) ( $badge['label'] ?? '' ) );
		if ( '' === $label ) {
			return '';
		}
		$key = sanitize_html_class( (string) ( $badge['key'] ?? '' ) );
		return sprintf(
			'<span class="%1$s%2$s">%3$s<span class="dlc-badge-text">%4$s</span></span>',
			esc_attr( $class ),
			'' !== $key ? ' ' . esc_attr( $class ) . '--' . esc_attr( $key ) : '',
			self::icon( (string) ( $badge['icon'] ?? '' ), 14 ),
			esc_html( $label )
		);
	}

	/** Emoji, variation selectors, joiners and keycaps removed; spaces tidied. Text stays as typed. */
	public static function strip_emoji( string $text ): string {
		$text = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{FE0E}\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', $text );
		return trim( (string) preg_replace( '/\s{2,}/u', ' ', (string) $text ) );
	}

	/** The drawn icon an emoji the owner typed stood for ('' when none). */
	public static function emoji_icon( string $text ): string {
		$map = array(
			'🔥' => 'flame', '⚡' => 'bolt', '⭐' => 'star', '🌟' => 'star', '✨' => 'sparkle', '🆕' => 'sparkle',
			'🎁' => 'gift', '💎' => 'award', '🏆' => 'award', '🥇' => 'award', '👑' => 'crown', '💯' => 'award',
			'🚚' => 'truck', '⏱' => 'clock', '⏰' => 'clock', '⌛' => 'clock', '⏳' => 'clock', '🛡' => 'shield',
			'🔒' => 'shield', '🚀' => 'rocket', '🏷' => 'tag', '💰' => 'tag', '✅' => 'check', '👍' => 'check',
		);
		foreach ( $map as $emoji => $icon ) {
			if ( false !== strpos( $text, $emoji ) ) {
				return $icon;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------- admin: product, bulk */

	public static function admin_boot(): void {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_delivery_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_delivery_box' ), 25, 1 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'after_variation_save' ), 30, 1 );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'backfill' ) );
	}

	public static function add_delivery_box(): void {
		add_meta_box( 'delicat-delivery-time', __( 'Délai de livraison', 'delicat-builder-v9' ), array( __CLASS__, 'delivery_box' ), 'product', 'side', 'default' );
	}

	public static function delivery_box( WP_Post $post ): void {
		$value = self::sanitize_delivery( get_post_meta( $post->ID, self::DELIVERY_META, true ) );
		wp_nonce_field( 'delicat_delivery_save', 'delicat_delivery_nonce' );
		echo '<p><label for="dlc-delivery" class="screen-reader-text">' . esc_html__( 'Délai de livraison', 'delicat-builder-v9' ) . '</label><select id="dlc-delivery" name="dlc_delivery" style="width:100%">';
		echo '<option value=""' . selected( $value, '', false ) . '>' . esc_html__( 'Réglage global (sinon : badges des packs)', 'delicat-builder-v9' ) . '</option>';
		foreach ( self::delivery_choices() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p class="description">' . esc_html__( 'Badge affiché une seule fois, dans l’en-tête PACKAGE de la fiche produit (celui du pack choisi quand les packs diffèrent). Une variation peut avoir son propre délai.', 'delicat-builder-v9' ) . '</p>';
	}

	public static function save_delivery_box( int $post_id ): void {
		if ( empty( $_POST['delicat_delivery_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['delicat_delivery_nonce'] ) ), 'delicat_delivery_save' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$value = self::sanitize_delivery( isset( $_POST['dlc_delivery'] ) ? wp_unslash( $_POST['dlc_delivery'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_delivery() whitelists
		'' === $value ? delete_post_meta( $post_id, self::DELIVERY_META ) : update_post_meta( $post_id, self::DELIVERY_META, $value );
		self::refresh_delivery_auto( $post_id );
	}

	public static function after_variation_save( $variation_id ): void {
		$parent = wp_get_post_parent_id( absint( $variation_id ) );
		if ( $parent ) {
			self::refresh_delivery_auto( (int) $parent );
		}
	}

	public static function bulk_actions( $actions ): array {
		$actions = is_array( $actions ) ? $actions : array();
		if ( ! current_user_can( 'edit_products' ) ) {
			return $actions;
		}
		foreach ( self::delivery_choices() as $key => $label ) {
			/* translators: %s: delivery time choice */
			$actions[ 'dlc_delivery_' . $key ] = sprintf( __( 'Délai : %s', 'delicat-builder-v9' ), $label );
		}
		$actions['dlc_delivery_inherit'] = __( 'Délai : réglage global', 'delicat-builder-v9' );
		return $actions;
	}

	/** WordPress checked the list's own nonce (bulk-posts) before calling this. */
	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( (string) $action, 'dlc_delivery_' ) ) {
			return $redirect;
		}
		check_admin_referer( 'bulk-posts' );
		$key   = substr( (string) $action, strlen( 'dlc_delivery_' ) );
		$value = 'inherit' === $key ? '' : self::sanitize_delivery( $key, false );
		$done  = 0;
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( ! $id || 'product' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			'' === $value ? delete_post_meta( $id, self::DELIVERY_META ) : update_post_meta( $id, self::DELIVERY_META, $value );
			self::refresh_delivery_auto( $id );
			++$done;
		}
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		return add_query_arg( 'dlc_delivery_done', $done, remove_query_arg( 'dlc_delivery_done', $redirect ) );
	}

	public static function bulk_notice(): void {
		if ( ! isset( $_GET['dlc_delivery_done'] ) || ! current_user_can( 'edit_products' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
			return;
		}
		$count = absint( $_GET['dlc_delivery_done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		/* translators: %d: number of products */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'Délai de livraison mis à jour sur %d produit.', 'Délai de livraison mis à jour sur %d produits.', $count, 'delicat-builder-v9' ), $count ) ) . '</p></div>';
	}

	/**
	 * Products saved before 9.12 get their card value once, 100 per admin page view, so the
	 * cards show what each product page already shows. Nothing runs on the storefront.
	 */
	public static function backfill(): void {
		if ( '1' === get_option( 'delicat_builder_v9_delivery_backfill' ) || ! current_user_can( 'edit_products' ) || wp_doing_ajax() ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_query'     => array( array( 'key' => self::DELIVERY_AUTO_META, 'compare' => 'NOT EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin, 100 at a time, once
			)
		);
		if ( ! $ids ) {
			update_option( 'delicat_builder_v9_delivery_backfill', '1', false );
			return;
		}
		foreach ( $ids as $id ) {
			self::refresh_delivery_auto( (int) $id );
		}
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

		if ( is_admin() ) {
			self::admin_boot();
		}
	}
}

Delicat_Builder_V9_Badges::boot();
