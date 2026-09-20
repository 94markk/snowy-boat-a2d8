<?php

defined( 'ABSPATH' ) || exit;

/**
 * Removes every trace of the upstream supplier from anything a site visitor can
 * observe: rendered HTML, asset URLs, customer order views, transactional email,
 * and the public REST index.
 */
final class DST2T_Privacy {
	/** @var array */
	private static $asset_cache = array();

	/**
	 * CSS class, data attribute, and form field prefix used on the storefront.
	 *
	 * The default prefix is searchable and leads straight back to this plugin and
	 * therefore to the provider, so stealth mode swaps it for a generic one. The
	 * prefix is applied to the markup and to the inlined CSS/JS together, so both
	 * sides always agree.
	 */
	public static function prefix() {
		return DST2T_Brand::stealth() ? 'topup' : 'dst2t';
	}

	/** Name of the requirement field array posted from the product form. */
	public static function field_key() {
		return self::prefix() . '_req';
	}

	public static function hooks() {
		add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'filter_item_meta' ), 20, 2 );
		add_filter( 'rest_index', array( __CLASS__, 'hide_rest_index' ), 20 );
		add_filter( 'rest_namespace_index', array( __CLASS__, 'hide_namespace_index' ), 20, 2 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( __CLASS__, 'filter_product_response' ), 20 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( __CLASS__, 'filter_product_response' ), 20 );
	}

	/**
	 * Enqueues the storefront assets. While stealth mode is on the CSS and JS are
	 * inlined instead of linked, so the plugin directory never appears in the
	 * page source of a public product page.
	 */
	public static function enqueue_frontend() {
		// The handle becomes the printed element id (dst2t-ui-js-after), so it has
		// to carry the neutral prefix as well, not just the CSS and markup.
		$prefix = self::prefix();
		$handle = $prefix . '-ui';

		if ( ! DST2T_Brand::stealth() ) {
			wp_enqueue_style( $handle, DST2T_URL . 'assets/frontend.css', array(), DST2T_VERSION );
			wp_enqueue_script( $handle, DST2T_URL . 'assets/frontend.js', array( 'jquery' ), DST2T_VERSION, true );
			return;
		}

		$css = str_replace( 'dst2t-', $prefix . '-', self::asset( 'assets/frontend.css' ) );
		$js  = str_replace( array( 'dst2t-', 'dst2t_' ), array( $prefix . '-', $prefix . '_' ), self::asset( 'assets/frontend.js' ) );

		if ( '' !== $css ) {
			wp_register_style( $handle, false, array(), DST2T_VERSION );
			wp_enqueue_style( $handle );
			wp_add_inline_style( $handle, $css );
		}
		if ( '' !== $js ) {
			wp_register_script( $handle, false, array( 'jquery' ), DST2T_VERSION, true );
			wp_enqueue_script( $handle );
			wp_add_inline_script( $handle, $js );
		}
	}

	/**
	 * Reads a bundled asset once per request.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	public static function asset( $relative ) {
		$relative = ltrim( (string) $relative, '/' );
		if ( isset( self::$asset_cache[ $relative ] ) ) {
			return self::$asset_cache[ $relative ];
		}
		$path = DST2T_PATH . $relative;
		$real = realpath( $path );
		$root = realpath( DST2T_PATH );
		if ( ! $real || ! $root || 0 !== strpos( $real, $root ) || ! is_readable( $real ) ) {
			self::$asset_cache[ $relative ] = '';
			return '';
		}
		self::$asset_cache[ $relative ] = (string) file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return self::$asset_cache[ $relative ];
	}

	/**
	 * Strips internal fulfillment metadata and any supplier branding from the
	 * customer-facing order table and order emails. WooCommerce already hides
	 * underscore-prefixed keys, so this is the second line of defence for the
	 * human-readable rows the plugin writes itself.
	 *
	 * @param array $formatted Formatted meta objects keyed by meta id.
	 */
	public static function filter_item_meta( $formatted, $item = null ) {
		if ( ! is_array( $formatted ) || ! DST2T_Brand::stealth() ) {
			return $formatted;
		}
		if ( ! self::customer_facing() ) {
			return $formatted;
		}

		foreach ( $formatted as $id => $meta ) {
			if ( ! is_object( $meta ) ) {
				continue;
			}
			$key = isset( $meta->key ) ? (string) $meta->key : '';
			if ( 0 === strpos( $key, '_dst2t' ) || 0 === strpos( $key, '_delicat' ) || 0 === strpos( $key, 'dst2t' ) ) {
				unset( $formatted[ $id ] );
				continue;
			}
			if ( self::mentions_supplier( $key ) || self::mentions_supplier( isset( $meta->value ) ? $meta->value : '' ) ) {
				unset( $formatted[ $id ] );
				continue;
			}
			if ( isset( $meta->display_key ) ) {
				$formatted[ $id ]->display_key = DST2T_Brand::scrub( $meta->display_key );
			}
			if ( isset( $meta->display_value ) && is_string( $meta->display_value ) ) {
				$formatted[ $id ]->display_value = DST2T_Brand::scrub( $meta->display_value );
			}
		}

		return $formatted;
	}

	/** Removes the plugin's namespaces from the publicly readable REST index. */
	public static function hide_rest_index( $response ) {
		if ( ! DST2T_Brand::stealth() || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();

		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values(
				array_filter(
					$data['namespaces'],
					static function ( $namespace ) {
						return ! DST2T_Webhook::owns_namespace( $namespace );
					}
				)
			);
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				if ( DST2T_Webhook::owns_namespace( trim( (string) $route, '/' ) ) ) {
					unset( $data['routes'][ $route ] );
				}
			}
		}

		$response->set_data( $data );
		return $response;
	}

	/** Returns 404 for a direct namespace index request on the plugin's namespaces. */
	public static function hide_namespace_index( $response, $request = null ) {
		if ( ! DST2T_Brand::stealth() || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$namespace = (string) $request->get_param( 'namespace' );
		if ( $namespace && DST2T_Webhook::owns_namespace( $namespace ) ) {
			return new WP_Error( 'rest_no_route', __( 'No route was found matching the URL and request method.', 'delicat-shop2topup' ), array( 'status' => 404 ) );
		}
		return $response;
	}

	/** Keeps fulfillment metadata out of the WooCommerce products REST response. */
	public static function filter_product_response( $response ) {
		if ( ! DST2T_Brand::stealth() || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
			$data['meta_data'] = array_values(
				array_filter(
					$data['meta_data'],
					static function ( $meta ) {
						$key = is_object( $meta ) && isset( $meta->key ) ? $meta->key : ( is_array( $meta ) && isset( $meta['key'] ) ? $meta['key'] : '' );
						return 0 !== strpos( (string) $key, '_dst2t' );
					}
				)
			);
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * True when the current render will be read by a customer.
	 *
	 * is_admin() alone is not enough: WooCommerce renders and sends the customer's
	 * order email during the admin request that changes an order's status, so an
	 * admin-context check would leak exactly the emails that matter most.
	 */
	private static function customer_facing() {
		foreach ( array( 'woocommerce_email_order_details', 'woocommerce_email_order_meta', 'woocommerce_email_before_order_table', 'woocommerce_email_after_order_table' ) as $email_action ) {
			if ( doing_action( $email_action ) ) {
				return true;
			}
		}
		return ! ( is_admin() && ! wp_doing_ajax() && current_user_can( 'manage_woocommerce' ) );
	}

	private static function mentions_supplier( $text ) {
		$text = strtolower( is_scalar( $text ) ? (string) $text : '' );
		if ( '' === $text ) {
			return false;
		}
		foreach ( DST2T_Brand::SUPPLIER_TOKENS as $token ) {
			if ( 's2t' === $token ) {
				continue; // Too short to match safely inside customer-entered values.
			}
			if ( false !== strpos( $text, $token ) ) {
				return true;
			}
		}
		return false;
	}
}
