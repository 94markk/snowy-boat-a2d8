<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic heart / favorites engine.
 *
 * Guests: first-party cookie only (RC55), no server write.
 * Logged-in customers: authenticated AJAX + nonce + user-meta.
 */
final class Delicat_Builder_V9_Heart_Engine {
	public const USER_META = '_delicat_builder_v9_favorites';
	public const AJAX_ACTION = 'delicat_builder_v9_favorite';
	public const NONCE_ACTION = 'delicat_builder_v9_favorite';
	public const MAX_FAVORITES = 200;
	public const RATE_LIMIT = 60;
	public const RATE_WINDOW = 300;

	private static ?array $favorites_cache = null;
	private static ?array $favorites_lookup = null;

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax' ) );
	}

	public static function enabled(): bool {
		if ( ! class_exists( 'Delicat_Builder_V9_Design' ) || ! Delicat_Builder_V9_Design::enabled() ) {
			return false;
		}
		$settings = Delicat_Builder_V9_Design::settings();
		return ! empty( $settings['heart_engine'] );
	}

	public static function guest_enabled(): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		$settings = Delicat_Builder_V9_Design::settings();
		return ! empty( $settings['heart_guest'] );
	}

	public static function favorites_for_current_user(): array {
		if ( ! is_user_logged_in() ) {
			return array();
		}

		if ( null !== self::$favorites_cache ) {
			return self::$favorites_cache;
		}

		$raw = get_user_meta( get_current_user_id(), self::USER_META, true );
		$ids = is_array( $raw ) ? $raw : array();
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids )
				)
			)
		);

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

	public static function frontend_config(): array {
		return array(
			'enabled'     => self::enabled(),
			'loggedIn'    => is_user_logged_in(),
			'guest'       => self::guest_enabled(),
			'ajaxUrl'     => is_user_logged_in() ? admin_url( 'admin-ajax.php' ) : '',
			'nonce'       => is_user_logged_in() ? wp_create_nonce( self::NONCE_ACTION ) : '',
			'storageKey'  => 'delicat_builder_v9_favorites_v1',
			'addLabel'    => __( 'Ajouter aux favoris', 'delicat-builder-v9' ),
			'removeLabel' => __( 'Retirer des favoris', 'delicat-builder-v9' ),
		);
	}

	private static function rate_limited( int $user_id ): bool {
		$key = 'dbv9_fav_rate_' . $user_id;
		$state = get_transient( $key );
		$state = is_array( $state ) ? $state : array(
			'count' => 0,
			'start' => time(),
		);

		if ( time() - absint( $state['start'] ?? 0 ) > self::RATE_WINDOW ) {
			$state = array( 'count' => 0, 'start' => time() );
		}

		$state['count'] = absint( $state['count'] ?? 0 ) + 1;
		set_transient( $key, $state, self::RATE_WINDOW );

		return $state['count'] > self::RATE_LIMIT;
	}

	public static function handle_ajax(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Method not allowed.', 'delicat-builder-v9' ) ), 405 );
		}

		$design = class_exists( 'Delicat_Builder_V9_Design' ) ? Delicat_Builder_V9_Design::settings() : array();
		if ( empty( $design['heart_engine'] ) ) {
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

		$product_id = absint( $_POST['product_id'] ?? 0 );
		$state = isset( $_POST['state'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['state'] ) );

		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product instanceof WC_Product || 'publish' !== get_post_status( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'delicat-builder-v9' ) ), 400 );
		}

		$favorites = self::favorites_for_current_user();
		$exists = in_array( $product_id, $favorites, true );

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
		self::$favorites_cache = $favorites;
		self::$favorites_lookup = array_fill_keys( $favorites, true );

		wp_send_json_success(
			array(
				'productId' => $product_id,
				'favorite'  => $state,
			)
		);
	}
}
