<?php
/**
 * Like engine: the one favourite button on every product card.
 *
 * pro.33 rebuild. This replaces three things that each drew a heart:
 *   - the dynamic heart bubble (a 44px disc that flipped to solid pink),
 *   - the decorative static bubble shown when the engine was switched off,
 *   - the RC81 tap pulse, which phones never played because a touch-device
 *     rule forced animation:none and transform:none on it.
 *
 * Storage is deliberately unchanged, so every customer keeps what they saved:
 *   - signed-in customers: user meta, written through a nonce-checked,
 *     rate-limited admin-ajax action;
 *   - guests: a small first-party cookie written by heart.js, never a
 *     server write and never an unauthenticated endpoint.
 *
 * The button markup is produced here and nowhere else on the server.
 * carousel.js builds the same tree from the same path for cards it
 * materialises later, and heart.js owns every movement.
 *
 * Requires PHP 8.3; the main plugin file refuses to load on anything older. (These typed class constants are the single feature that sets that floor.)
 */

defined( 'ABSPATH' ) || exit;

final class Delicat_Builder_V9_Heart_Engine {
	public const string USER_META    = '_delicat_builder_v9_favorites';
	public const string AJAX_ACTION  = 'delicat_builder_v9_favorite';
	public const string NONCE_ACTION = 'delicat_builder_v9_favorite';
	public const string GUEST_COOKIE = 'dbv9_fav';
	public const int MAX_FAVORITES   = 200;
	public const int RATE_LIMIT      = 60;
	public const int RATE_WINDOW     = 300;

	/**
	 * The heart, on a 24px grid. Stroked for the resting state and filled
	 * for the liked state: one silhouette, so the two never disagree.
	 */
	public const string HEART_PATH = 'M12 20.6C11.4 20.2 3 14.9 3 9.1 3 6.4 5 4.4 7.5 4.4c1.9 0 3.6 1.1 4.5 2.7.9-1.6 2.6-2.7 4.5-2.7 2.5 0 4.5 2 4.5 4.7 0 5.8-8.4 11.1-9 11.5Z';

	private static ?array $favorites_cache  = null;
	private static ?array $favorites_lookup = null;
	private static ?array $switches         = null;

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle_ajax' ) );
	}

	/**
	 * The two Design Studio switches this engine obeys, read from the stored
	 * option itself.
	 *
	 * pro.34: the engine used to ask Delicat_Builder_V9_Design, but the runtime
	 * router only loads that class on product and shop routes, and only when
	 * Design Studio is switched on. On the homepage and on the favourite AJAX
	 * request the class never existed, so the engine reported itself off: no
	 * like button rendered, heart.js never started, and a signed-in save was
	 * refused as "Favorites are disabled". The old decorative bubble was what
	 * filled that gap, which is why that heart never reacted to a tap.
	 *
	 * Missing keys fall back to Design Studio's own defaults: both on.
	 *
	 * @return array{engine: bool, guest: bool}
	 */
	private static function switches(): array {
		if ( null !== self::$switches ) {
			return self::$switches;
		}

		if ( class_exists( 'Delicat_Builder_V9_Design', false ) ) {
			$stored = Delicat_Builder_V9_Design::settings();
		} else {
			$stored = get_option( 'delicat_builder_v9_design', array() );
			$stored = is_array( $stored ) ? $stored : array();
		}

		self::$switches = array(
			'engine' => ! array_key_exists( 'heart_engine', $stored ) || ! empty( $stored['heart_engine'] ),
			'guest'  => ! array_key_exists( 'heart_guest', $stored ) || ! empty( $stored['heart_guest'] ),
		);
		return self::$switches;
	}

	/** On unless the merchant switched the Dynamic Heart Engine off. Not tied to Design Studio's page scope. */
	public static function enabled(): bool {
		return self::switches()['engine'];
	}

	public static function guest_enabled(): bool {
		return self::enabled() && self::switches()['guest'];
	}

	/** @return array{add: string, remove: string} */
	public static function labels(): array {
		return array(
			'add'    => __( 'Ajouter aux favoris', 'delicat-builder-v9' ),
			'remove' => __( 'Retirer des favoris', 'delicat-builder-v9' ),
		);
	}

	/** @return list<int> */
	public static function favorites_for_current_user(): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		if ( null !== self::$favorites_cache ) {
			return self::$favorites_cache;
		}

		$raw = get_user_meta( get_current_user_id(), self::USER_META, true );
		$ids = is_array( $raw ) ? $raw : array();
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		self::$favorites_cache = array_slice( $ids, 0, self::MAX_FAVORITES );
		return self::$favorites_cache;
	}

	public static function is_favorite( int $product_id ): bool {
		if ( $product_id <= 0 || ! is_user_logged_in() ) {
			return false;
		}

		if ( null === self::$favorites_lookup ) {
			self::$favorites_lookup = array_fill_keys( self::favorites_for_current_user(), true );
		}

		return isset( self::$favorites_lookup[ $product_id ] );
	}

	/**
	 * The like button for one product card.
	 *
	 * A 44px transparent hit area holding a smaller visible chip, so the tap
	 * target stays generous while the artwork stays the subject. Both SVGs
	 * carry width/height, so the button can never balloon to an SVG's
	 * default 300x150 on a request whose stylesheet has not arrived yet.
	 */
	public static function render_button( int $product_id, bool $liked = false ): string {
		if ( $product_id <= 0 ) {
			return '';
		}

		$labels = self::labels();
		$path   = esc_attr( self::HEART_PATH );

		return '<button type="button" class="delicat-like delicat-product-card__like" data-delicat-like'
			. ' data-product-id="' . esc_attr( (string) $product_id ) . '"'
			. ' aria-pressed="' . ( $liked ? 'true' : 'false' ) . '"'
			. ' aria-label="' . esc_attr( $liked ? $labels['remove'] : $labels['add'] ) . '">'
			. '<span class="delicat-like__chip" aria-hidden="true">'
			. '<svg class="delicat-like__outline" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path d="' . $path . '"/></svg>'
			. '<svg class="delicat-like__fill" viewBox="0 0 24 24" width="18" height="18" focusable="false"><path d="' . $path . '"/></svg>'
			. '</span>'
			. '</button>';
	}

	/**
	 * Resting outline colour for a chip background: dark ink on light chips,
	 * white ink on dark ones. Computed here because the storefront CSS may not
	 * use color-mix() or relative colours (Android WebView).
	 */
	public static function ink_for( string $background ): string {
		$hex = ltrim( trim( $background ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#1f2340';
		}

		$channel = static function ( string $pair ): float {
			$value = hexdec( $pair ) / 255;
			return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		};

		$luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) )
			+ 0.7152 * $channel( substr( $hex, 2, 2 ) )
			+ 0.0722 * $channel( substr( $hex, 4, 2 ) );

		return $luminance > 0.36 ? '#1f2340' : '#ffffff';
	}

	public static function frontend_config(): array {
		$logged_in = is_user_logged_in();
		$labels    = self::labels();

		return array(
			'enabled'     => self::enabled(),
			'loggedIn'    => $logged_in,
			'guest'       => self::guest_enabled(),
			'ajaxUrl'     => $logged_in ? admin_url( 'admin-ajax.php' ) : '',
			'nonce'       => $logged_in ? wp_create_nonce( self::NONCE_ACTION ) : '',
			'action'      => self::AJAX_ACTION,
			'cookie'      => self::GUEST_COOKIE,
			'path'        => self::HEART_PATH,
			'addLabel'    => $labels['add'],
			'removeLabel' => $labels['remove'],
		);
	}

	private static function rate_limited( int $user_id ): bool {
		$key   = 'dbv9_fav_rate_' . $user_id;
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || $now - absint( $state['start'] ?? 0 ) > self::RATE_WINDOW ) {
			$state = array(
				'count' => 0,
				'start' => $now,
			);
		}

		$state['count'] = absint( $state['count'] ?? 0 ) + 1;
		set_transient( $key, $state, self::RATE_WINDOW );

		return $state['count'] > self::RATE_LIMIT;
	}

	public static function handle_ajax(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Method not allowed.', 'delicat-builder-v9' ) ), 405 );
		}

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Favorites are disabled.', 'delicat-builder-v9' ) ), 403 );
		}

		if ( ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce unavailable.', 'delicat-builder-v9' ) ), 503 );
		}

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Authentication required.', 'delicat-builder-v9' ) ), 401 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$user_id = get_current_user_id();
		if ( self::rate_limited( $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests.', 'delicat-builder-v9' ) ), 429 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
		$product_id = isset( $_POST['product_id'] ) && is_scalar( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$raw_state  = isset( $_POST['state'] ) && is_string( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$state = '1' === $raw_state;

		$product = $product_id > 0 ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product || 'publish' !== get_post_status( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'delicat-builder-v9' ) ), 400 );
		}

		$favorites = self::favorites_for_current_user();
		$exists    = in_array( $product_id, $favorites, true );

		if ( $state && ! $exists ) {
			if ( count( $favorites ) >= self::MAX_FAVORITES ) {
				wp_send_json_error( array( 'message' => __( 'Favorite limit reached.', 'delicat-builder-v9' ) ), 400 );
			}
			$favorites[] = $product_id;
		} elseif ( ! $state && $exists ) {
			$favorites = array_values( array_diff( $favorites, array( $product_id ) ) );
		}

		$favorites = array_values( array_unique( array_map( 'absint', $favorites ) ) );
		update_user_meta( $user_id, self::USER_META, $favorites );
		self::$favorites_cache  = $favorites;
		self::$favorites_lookup = array_fill_keys( $favorites, true );

		wp_send_json_success(
			array(
				'productId' => $product_id,
				'favorite'  => $state,
			)
		);
	}
}
