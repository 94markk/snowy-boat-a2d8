<?php
/**
 * One badge engine. At most two badges per product, in fixed slots:
 *
 *   TOPIC   from the product's own tags and categories, matched against the
 *           six the store sells under — Jeux, Finance, Gift Card, Réseaux
 *           Sociaux, Streaming, Mobile. Tag ORDER decides: the merchant
 *           chooses the badge by ordering the tags.
 *
 *   STATUS  #1, then Promo, then Top Vente. Rarest first: every shop has
 *           promotions and only one product is number one.
 *
 * Every value is WooCommerce's: tags are the merchant's taxonomy, Promo is
 * is_on_sale(), Top Vente and #1 come from total_sales.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Topic table. Aliases are compared after ds_normalize_term(), so accents,
 * case and separators do not matter, and the common misspelling
 * "Resaux-Sociaux" is a first-class alias.
 *
 * @return array<string,array{label:string,icon:string,match:string[]}>
 */
function ds_badge_topics(): array {
	return array(
		'jeux'            => array(
			'label' => 'Jeux',
			'icon'  => 'gamepad',
			'match' => array( 'jeux', 'jeu', 'gaming', 'game', 'games', 'jeux-video', 'free-fire', 'pubg', 'mobile-legends', 'dream-league', 'efootball', 'roblox', 'fortnite', 'call-of-duty', 'cod' ),
		),
		'finance'         => array(
			'label' => 'Finance',
			'icon'  => 'wallet',
			'match' => array( 'finance', 'financier', 'echanges', 'echange', 'exchange', 'moncash', 'mon-cash', 'natcash', 'transfert', 'paiement', 'crypto', 'usdt', 'bitcoin', 'btc', 'payoneer', 'paypal' ),
		),
		'gift-card'       => array(
			'label' => 'Gift Card',
			'icon'  => 'gift',
			'match' => array( 'gift-card', 'giftcard', 'gift-cards', 'carte-cadeau', 'cartes-cadeaux', 'carte-cadeaux', 'google-play', 'itunes', 'apple', 'playstation', 'psn', 'xbox', 'steam', 'amazon', 'razer-gold' ),
		),
		'reseaux-sociaux' => array(
			'label' => 'Réseaux sociaux',
			'icon'  => 'share',
			'match' => array( 'reseaux-sociaux', 'resaux-sociaux', 'reseaux', 'resaux', 'social', 'socials', 'social-media', 'instagram', 'tiktok', 'facebook', 'youtube', 'abonnes', 'followers', 'likes' ),
		),
		'streaming'       => array(
			'label' => 'Streaming',
			'icon'  => 'play',
			'match' => array( 'streaming', 'stream', 'netflix', 'spotify', 'video', 'abonnement', 'abonnements', 'disney', 'prime-video', 'canva', 'chatgpt', 'iptv', 'deezer', 'apple-music' ),
		),
		'mobile'          => array(
			'label' => 'Mobile',
			'icon'  => 'phone',
			'match' => array( 'mobile', 'recharge-mobile', 'telephone', 'telephonie', 'minutes', 'forfait', 'natcom', 'digicel', 'data', 'internet' ),
		),
	);
}

/**
 * Pick the topic for an ordered list of term names/slugs. Pure.
 *
 * @param string[] $terms Names and slugs in the order the merchant set them.
 * @return string|null Topic key.
 */
function ds_badge_topic_for( array $terms ): ?string {
	$topics = ds_badge_topics();
	foreach ( $terms as $raw ) {
		$term = ds_normalize_term( (string) $raw );
		if ( '' === $term ) {
			continue;
		}
		foreach ( $topics as $key => $topic ) {
			if ( $key === $term || in_array( $term, $topic['match'], true ) ) {
				return $key;
			}
		}
	}
	return null;
}

/**
 * Pick the status badge. Pure.
 *
 * @param bool $is_rank_one Best seller of the store.
 * @param bool $on_sale     is_on_sale().
 * @param int  $sales       total_sales.
 * @param int  $threshold   Top Vente threshold.
 * @return array{key:string,label:string,icon:string,tone:string}|null
 */
function ds_badge_status_for( bool $is_rank_one, bool $on_sale, int $sales, int $threshold ): ?array {
	if ( $is_rank_one ) {
		return array( 'key' => 'rank-1', 'label' => '#1', 'icon' => 'crown', 'tone' => 'rank' );
	}
	if ( $on_sale ) {
		return array( 'key' => 'promo', 'label' => 'Promo', 'icon' => 'tag', 'tone' => 'promo' );
	}
	if ( $threshold > 0 && $sales >= $threshold ) {
		return array( 'key' => 'top-vente', 'label' => 'Top Vente', 'icon' => 'flame', 'tone' => 'top' );
	}
	return null;
}

/**
 * The store's number one: the published product with the most sales, and at
 * least three of them. One query an hour for the whole store; the cache is
 * flushed when an order completes (see ds_flush_catalog_caches()).
 */
function ds_rank_one_id(): int {
	$cached = get_transient( 'ds_rank_one' );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	$ids = wc_get_products(
		array(
			'status'   => 'publish',
			'limit'    => 1,
			'orderby'  => 'meta_value_num',
			'meta_key' => 'total_sales', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'    => 'DESC',
			'return'   => 'ids',
		)
	);
	$id = 0;
	if ( ! empty( $ids[0] ) ) {
		$sales = (int) get_post_meta( (int) $ids[0], 'total_sales', true );
		$id    = $sales >= 3 ? (int) $ids[0] : 0;
	}
	set_transient( 'ds_rank_one', $id, HOUR_IN_SECONDS );
	return $id;
}

/**
 * Ordered term names and slugs of a product: tags first (merchant-ordered),
 * then categories.
 *
 * @return string[]
 */
function ds_product_term_words( WC_Product $product ): array {
	$id    = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	$words = array();
	foreach ( array( 'product_tag', 'product_cat' ) as $tax ) {
		$terms = get_the_terms( $id, $tax );
		if ( ! is_array( $terms ) ) {
			continue;
		}
		foreach ( $terms as $term ) {
			$words[] = $term->slug;
			$words[] = $term->name;
		}
	}
	return $words;
}

/**
 * Badges for a product.
 *
 * @return array{topic:?array,status:?array,instant:?array}
 */
function ds_product_badges( WC_Product $product ): array {
	$topics = ds_badge_topics();
	$topic  = null;
	$key    = ds_badge_topic_for( ds_product_term_words( $product ) );
	if ( null !== $key ) {
		$topic = array(
			'key'   => $key,
			'label' => $topics[ $key ]['label'],
			'icon'  => $topics[ $key ]['icon'],
			'tone'  => 'topic',
		);
	}
	$status = ds_badge_status_for(
		ds_rank_one_id() === $product->get_id(),
		$product->is_on_sale(),
		(int) $product->get_total_sales(),
		(int) ds_opt( 'top_threshold' )
	);
	$instant = null;
	if ( (int) ds_opt( 'instant_badge' ) === 1 && ( $product->is_virtual() || $product->is_downloadable() ) ) {
		$instant = array( 'key' => 'instant', 'label' => 'Livraison instantanée', 'icon' => 'bolt', 'tone' => 'instant' );
	}
	return array(
		'topic'   => $topic,
		'status'  => $status,
		'instant' => $instant,
	);
}

/**
 * Markup for one badge.
 *
 * @param array{key:string,label:string,icon:string,tone:string} $badge Badge.
 */
function ds_badge_html( array $badge ): string {
	return '<span class="ds-badge ds-badge--' . esc_attr( $badge['tone'] ) . ' ds-badge--k-' . esc_attr( $badge['key'] ) . '">' . ds_icon( $badge['icon'], 'ds-badge__icon' ) . '<span>' . esc_html( $badge['label'] ) . '</span></span>';
}
