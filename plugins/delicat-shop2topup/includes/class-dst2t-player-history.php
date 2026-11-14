<?php

defined( 'ABSPATH' ) || exit;

/** Private encrypted last-five Player ID tuples per customer and supplier category. */
final class DST2T_Player_History {
	const AJAX_ACTION = 'dst2t_player_history';

	/** @var DST2T_Product */
	private $products;

	/** @var DST2T_Vault */
	private $vault;

	/** @var DST2T_Settings */
	private $settings;

	public function __construct( DST2T_Product $products, DST2T_Vault $vault, DST2T_Settings $settings ) {
		$this->products = $products;
		$this->vault    = $vault;
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
		add_action( 'woocommerce_add_to_cart', array( $this, 'remember_cart' ), 20, 6 );
		add_action( 'dst2t_player_verified', array( $this, 'remember_verified' ), 10, 2 );
	}

	public function remember_verified( $product_id, $requirements ) {
		$this->remember_cart( '', $product_id, 1, 0, array(), array( 'dst2t' => array( 'requirements' => $requirements ) ) );
	}

	/** @return array|false */
	private function scope( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}

		$mapped_id = $product_id;
		if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() && ! $this->products->is_mapped( $mapped_id ) ) {
			$mapped_id = absint( $product->get_parent_id() );
		}
		if ( ! $this->products->is_mapped( $mapped_id ) ) {
			return false;
		}

		$mapping = $this->products->mapping( $mapped_id );
		$spec    = array();
		$has_player = false;
		foreach ( (array) $mapping['requirements'] as $field ) {
			if ( empty( $field['field_name'] ) ) {
				continue;
			}
			$key = sanitize_key( $field['field_name'] );
			if ( '' === $key ) {
				continue;
			}
			$role = DST2T_Product::is_player_identifier_key( $key ) ? 'player' : 'aux';
			if ( 'player' === $role ) {
				$has_player = true;
			}
			$spec[ $key ] = $role;
		}
		if ( ! $has_player || ! $mapping['category_id'] ) {
			return false;
		}

		return array(
			'product_id' => $mapped_id,
			'key'        => '_dst2t_player_history_' . hash( 'sha256', (string) $mapping['category_id'] ),
			'spec'       => $spec,
		);
	}

	private function read( $user_id, $key ) {
		$encoded = (string) get_user_meta( $user_id, $key, true );
		if ( '' === $encoded ) {
			return array();
		}
		$plain = $this->vault->decrypt_context( $encoded, 'player-history|' . $user_id . '|' . $key );
		$rows  = json_decode( (string) $plain, true );
		return is_array( $rows ) ? array_slice( $rows, 0, 5 ) : array();
	}

	public static function merge( array $rows, array $fields ) {
		ksort( $fields );
		$id  = hash( 'sha256', wp_json_encode( $fields ) );
		$out = array( array( 'id' => $id, 'fields' => $fields ) );
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['id'], $row['fields'] ) && is_array( $row['fields'] ) && ! hash_equals( (string) $row['id'], $id ) ) {
				$out[] = $row;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	private function lock( $user_id, $key ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return '__no_db_lock__';
		}
		$name = 'dst2t_hist_' . substr( hash( 'sha256', $wpdb->prefix . '|' . $user_id . '|' . $key ), 0, 44 );
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ? $name : '';
	}

	private function unlock( $lock ) {
		if ( '__no_db_lock__' === $lock || '' === $lock ) {
			return;
		}
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public function remember_cart( $cart_key, $product_id, $quantity, $variation_id, $variation, $cart_data ) {
		if ( ! is_user_logged_in() || ! $this->settings->enabled( 'player_history' ) ) {
			return;
		}
		$lock = '';
		try {
			$scope = $this->scope( $variation_id ? $variation_id : $product_id );
			if ( ! $scope || empty( $cart_data['dst2t']['requirements'] ) || ! is_array( $cart_data['dst2t']['requirements'] ) ) {
				return;
			}
			$fields = array();
			foreach ( $scope['spec'] as $key => $role ) {
				if ( ! array_key_exists( $key, $cart_data['dst2t']['requirements'] ) || ! is_scalar( $cart_data['dst2t']['requirements'][ $key ] ) ) {
					continue;
				}
				$value = trim( sanitize_text_field( (string) $cart_data['dst2t']['requirements'][ $key ] ) );
				if ( '' !== $value && strlen( $value ) <= 190 ) {
					$fields[ $key ] = $value;
				}
			}
			$has_player = false;
			foreach ( $fields as $key => $value ) {
				if ( isset( $scope['spec'][ $key ] ) && 'player' === $scope['spec'][ $key ] ) {
					$has_player = true;
					break;
				}
			}
			if ( ! $has_player ) {
				return;
			}

			$user_id = get_current_user_id();
			$lock    = $this->lock( $user_id, $scope['key'] );
			if ( '' === $lock ) {
				return;
			}
			$rows    = self::merge( $this->read( $user_id, $scope['key'] ), $fields );
			$encoded = $this->vault->encrypt_context( wp_json_encode( $rows ), 'player-history|' . $user_id . '|' . $scope['key'] );
			if ( ! is_wp_error( $encoded ) ) {
				update_user_meta( $user_id, $scope['key'], $encoded );
			}
		} catch ( Throwable $error ) {
			// Never log player identifiers and never interrupt purchasing for history.
		} finally {
			$this->unlock( $lock );
		}
	}

	public function ajax() {
		nocache_headers();
		if ( ! is_user_logged_in() || ! $this->settings->enabled( 'player_history' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sign in to use saved Player IDs.', 'delicat-shop2topup' ) ), 401 );
		}
		check_ajax_referer( 'dst2t_player_history', 'nonce' );
		$product_id = isset( $_POST['product_id'] ) && is_scalar( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$scope      = $this->scope( $product_id );
		if ( ! $scope ) {
			wp_send_json_error( array( 'message' => __( 'Saved IDs are not available for this product.', 'delicat-shop2topup' ) ), 404 );
		}
		$user_id = get_current_user_id();

		if ( isset( $_POST['clear'] ) && '1' === (string) $_POST['clear'] ) {
			$lock = $this->lock( $user_id, $scope['key'] );
			if ( '' === $lock ) {
				wp_send_json_error( array( 'message' => __( 'Please try again.', 'delicat-shop2topup' ) ), 409 );
			}
			try {
				delete_user_meta( $user_id, $scope['key'] );
			} finally {
				$this->unlock( $lock );
			}
		}

		$safe = array();
		foreach ( $this->read( $user_id, $scope['key'] ) as $row ) {
			if ( ! is_array( $row ) || empty( $row['fields'] ) || ! is_array( $row['fields'] ) ) {
				continue;
			}
			$fields = array_intersect_key( $row['fields'], $scope['spec'] );
			if ( $fields ) {
				$safe[] = array( 'fields' => $fields );
			}
		}
		wp_send_json_success( array( 'items' => $safe, 'spec' => $scope['spec'] ) );
	}
}
