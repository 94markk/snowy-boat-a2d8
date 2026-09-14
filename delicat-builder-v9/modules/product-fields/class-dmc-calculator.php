<?php
/**
 * Cost Calculator / Product Fields — runtime.
 *
 * Renders configurable input fields on the product page, recomputes the price
 * AUTHORITATIVELY on the server (browser values are never trusted), and feeds
 * the result through the cart, checkout and order. Currency conversion is
 * handled by the price engine, so totals are shown and charged in the
 * customer's selected currency.
 *
 * @package DeliMultiCurrency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Delicat_Builder_V9_Product_Fields_Calculator {

	const META = '_dmc_calc';

	/** Field types that collect a value (vs. content-only). */
	const INPUT_TYPES = array( 'number', 'range', 'select', 'radio', 'checkbox', 'toggle', 'text', 'textarea', 'email', 'tel', 'password', 'url', 'date', 'hidden' );

	/** Field types that contribute to the price. */
	const PRICED_TYPES = array( 'number', 'range', 'select', 'radio', 'checkbox', 'toggle', 'hidden' );

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_prices' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'cart_item_display' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_line_item' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_secure_cart_items' ), 8 );
	}

	/* --------------------------------------------------------------------- */
	/* Config                                                                 */
	/* --------------------------------------------------------------------- */

	public function get_config( $product_id ) {
		$raw = get_post_meta( $product_id, self::META, true );
		if ( empty( $raw ) ) {
			return null;
		}
		$cfg = is_array( $raw ) ? $raw : json_decode( $raw, true );
		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) || empty( $cfg['fields'] ) || ! is_array( $cfg['fields'] ) ) {
			return null;
		}
		return $cfg;
	}

	public function enabled( $product_id ) {
		return null !== $this->get_config( $product_id );
	}

	/* --------------------------------------------------------------------- */
	/* Server-side evaluation (authoritative)                                 */
	/* --------------------------------------------------------------------- */

	protected function active_map( $fields, $values ) {
		$active = array();
		foreach ( $fields as $f ) {
			$active[ $f['id'] ] = true;
		}
		$passes = max( 1, count( $fields ) );
		for ( $p = 0; $p < $passes; $p++ ) {
			$changed = false;
			foreach ( $fields as $f ) {
				$ok = $this->conditions_pass( $f, $values, $active );
				if ( $active[ $f['id'] ] !== $ok ) {
					$active[ $f['id'] ] = $ok;
					$changed            = true;
				}
			}
			if ( ! $changed ) {
				break;
			}
		}
		return $active;
	}

	protected function conditions_pass( $field, $values, $active ) {
		if ( empty( $field['conditions'] ) || ! is_array( $field['conditions'] ) ) {
			return true;
		}
		$logic   = ( isset( $field['condition_logic'] ) && 'any' === $field['condition_logic'] ) ? 'any' : 'all';
		$results = array();
		foreach ( $field['conditions'] as $cond ) {
			$ref        = isset( $cond['field'] ) ? $cond['field'] : '';
			$op         = isset( $cond['op'] ) ? $cond['op'] : '==';
			$cmp        = isset( $cond['value'] ) ? $cond['value'] : '';
			$ref_active = isset( $active[ $ref ] ) ? $active[ $ref ] : true;
			$val        = ( $ref_active && isset( $values[ $ref ] ) ) ? $values[ $ref ] : '';
			$results[]  = $this->compare( $val, $op, $cmp );
		}
		return ( 'any' === $logic ) ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	protected function compare( $a, $op, $b ) {
		if ( is_array( $a ) ) {
			$has = in_array( (string) $b, array_map( 'strval', $a ), true );
			return ( '!=' === $op || 'not_contains' === $op ) ? ! $has : $has;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			$a = (float) $a;
			$b = (float) $b;
			switch ( $op ) {
				case '>':
					return $a > $b;
				case '<':
					return $a < $b;
				case '>=':
					return $a >= $b;
				case '<=':
					return $a <= $b;
				case '!=':
					return $a !== $b;
				default:
					return $a === $b;
			}
		}
		$a = (string) $a;
		$b = (string) $b;
		if ( 'contains' === $op ) {
			return '' === $b ? true : false !== strpos( $a, $b );
		}
		if ( 'not_contains' === $op ) {
			return '' === $b ? false : false === strpos( $a, $b );
		}
		return ( '!=' === $op ) ? $a !== $b : $a === $b;
	}

	/**
	 * Convert a raw amount into a price using its mode (fixed amount or % of base).
	 */
	protected function unit_price( $amount, $base, $type ) {
		$amount = (float) $amount;
		return ( 'percent' === $type ) ? ( $base * $amount / 100 ) : $amount;
	}

	protected function contribution( $field, $values, $base ) {
		$id    = $field['id'];
		$type  = $field['type'];
		$val   = isset( $values[ $id ] ) ? $values[ $id ] : '';
		$ptype = ( isset( $field['price_type'] ) && 'percent' === $field['price_type'] ) ? 'percent' : 'fixed';
		$price = isset( $field['price'] ) ? (float) $field['price'] : 0.0;

		switch ( $type ) {
			case 'number':
			case 'range':
				$num = is_numeric( $val ) ? (float) $val : 0.0;
				if ( isset( $field['min'] ) && '' !== $field['min'] ) {
					$num = max( (float) $field['min'], $num );
				}
				if ( isset( $field['max'] ) && '' !== $field['max'] ) {
					$num = min( (float) $field['max'], $num );
				}
				return $num * $this->unit_price( $price, $base, $ptype );

			case 'toggle':
				return ( 'yes' === $val ) ? $this->unit_price( $price, $base, $ptype ) : 0.0;

			case 'select':
			case 'radio':
				$opt = $this->find_option( $field, $val );
				return $opt ? $this->unit_price( $opt['price'], $base, $ptype ) : 0.0;

			case 'checkbox':
				$sum = 0.0;
				foreach ( (array) $val as $v ) {
					$opt = $this->find_option( $field, $v );
					if ( $opt ) {
						$sum += $this->unit_price( $opt['price'], $base, $ptype );
					}
				}
				return $sum;

			case 'hidden':
				return $this->unit_price( $price, $base, $ptype );

			default:
				return 0.0;
		}
	}

	protected function find_option( $field, $value ) {
		if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
			return null;
		}
		foreach ( $field['options'] as $opt ) {
			if ( isset( $opt['value'] ) && (string) $opt['value'] === (string) $value ) {
				return $opt;
			}
		}
		return null;
	}

	/**
	 * Numeric VALUE of a field for use inside the formula as {field_id}.
	 *
	 * Numbers/ranges return the entered value; toggles 1/0; dropdowns & radios
	 * the chosen option's price; checkboxes the sum of chosen prices. This is
	 * deliberately different from contribution(), which is the price added in
	 * no-formula (auto-sum) mode. It is what makes "{quantity} * {rate}" work.
	 *
	 * @param array $field  Field.
	 * @param array $values Submitted values.
	 * @return float
	 */
	protected function formula_value( $field, $values ) {
		$id   = $field['id'];
		$type = $field['type'];
		$val  = isset( $values[ $id ] ) ? $values[ $id ] : '';
		switch ( $type ) {
			case 'number':
			case 'range':
				$num = is_numeric( $val ) ? (float) $val : 0.0;
				if ( isset( $field['min'] ) && '' !== $field['min'] ) {
					$num = max( (float) $field['min'], $num );
				}
				if ( isset( $field['max'] ) && '' !== $field['max'] ) {
					$num = min( (float) $field['max'], $num );
				}
				return $num;
			case 'toggle':
				return ( 'yes' === $val ) ? 1.0 : 0.0;
			case 'select':
			case 'radio':
				$opt = $this->find_option( $field, $val );
				return $opt ? (float) $opt['price'] : 0.0;
			case 'checkbox':
				$sum = 0.0;
				foreach ( (array) $val as $v ) {
					$opt = $this->find_option( $field, $v );
					if ( $opt ) {
						$sum += (float) $opt['price'];
					}
				}
				return $sum;
			case 'hidden':
				$d = isset( $field['default'] ) ? $field['default'] : '';
				return is_numeric( $d ) ? (float) $d : (float) ( isset( $field['price'] ) ? $field['price'] : 0 );
			default:
				return 0.0;
		}
	}

	public function sanitize_values( $cfg, $raw ) {
		$values = array();
		$errors = array();
		$raw    = is_array( $raw ) ? $raw : array();

		foreach ( $cfg['fields'] as $f ) {
			$id   = $f['id'];
			$type = $f['type'];
			$in   = isset( $raw[ $id ] ) ? $raw[ $id ] : '';
			if ( 'checkbox' !== $type && ! is_scalar( $in ) ) {
				$errors[] = sprintf( __( 'Please enter a valid value for "%s".', 'deli-multi-currency' ), $f['label'] );
				$in = '';
			}

			switch ( $type ) {
				case 'number':
				case 'range':
					$valid_number = is_numeric( $in ) && is_finite( (float) $in );
					$values[ $id ] = $valid_number ? (float) $in : '';
					if ( '' !== $in && ( ! $valid_number
						|| ( isset( $f['min'] ) && '' !== $f['min'] && (float) $in < (float) $f['min'] )
						|| ( isset( $f['max'] ) && '' !== $f['max'] && (float) $in > (float) $f['max'] )
					) ) {
						$errors[] = sprintf( __( 'Please enter a valid value for "%s".', 'deli-multi-currency' ), $f['label'] );
					}
					break;
				case 'checkbox':
					$clean = array();
					foreach ( (array) $in as $v ) {
						if ( is_scalar( $v ) && $this->find_option( $f, $v ) ) {
							$clean[] = (string) sanitize_text_field( $v );
						}
					}
					$values[ $id ] = array_values( array_unique( $clean ) );
					break;
				case 'select':
				case 'radio':
					$v             = sanitize_text_field( (string) $in );
					$values[ $id ] = $this->find_option( $f, $v ) ? $v : '';
					break;
				case 'toggle':
					$values[ $id ] = ( $in && 'no' !== $in ) ? 'yes' : '';
					break;
				case 'email':
					$raw_email     = trim( (string) $in );
					$v             = sanitize_email( $raw_email );
					$values[ $id ] = $v;
					if ( '' !== $raw_email && ! is_email( $raw_email ) ) {
						$errors[] = ! empty( $f['error_message'] ) ? $f['error_message'] : sprintf( __( 'Please enter a valid e-mail for "%s".', 'deli-multi-currency' ), $f['label'] );
					}
					break;
				case 'tel':
					$raw_tel       = trim( sanitize_text_field( (string) $in ) );
					$digits        = preg_replace( '/\D+/', '', $raw_tel );
					$values[ $id ] = $raw_tel;
					if ( '' !== $raw_tel && ( strlen( $digits ) < 7 || strlen( $digits ) > 20 ) ) {
						$errors[] = ! empty( $f['error_message'] ) ? $f['error_message'] : sprintf( __( 'Please enter a valid phone number for "%s".', 'deli-multi-currency' ), $f['label'] );
					}
					break;
				case 'password':
					$values[ $id ] = sanitize_text_field( (string) $in );
					break;
				case 'url':
					$raw_url       = trim( (string) $in );
					$v             = esc_url_raw( $raw_url );
					$values[ $id ] = $v;
					if ( '' !== $raw_url && ( '' === $v || ! wp_http_validate_url( $v ) ) ) {
						$errors[] = ! empty( $f['error_message'] ) ? $f['error_message'] : sprintf( __( 'Please enter a valid URL for "%s".', 'deli-multi-currency' ), $f['label'] );
					}
					break;
				case 'date':
					$v = trim( sanitize_text_field( (string) $in ) );
					$valid_date = '' === $v;
					if ( '' !== $v && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
						$dt = DateTime::createFromFormat( '!Y-m-d', $v );
						$valid_date = $dt && $dt->format( 'Y-m-d' ) === $v;
					}
					$values[ $id ] = $valid_date ? $v : '';
					if ( '' !== $v && ! $valid_date ) {
						$errors[] = ! empty( $f['error_message'] ) ? $f['error_message'] : sprintf( __( 'Please enter a valid date for "%s".', 'deli-multi-currency' ), $f['label'] );
					}
					break;
				case 'textarea':
					$values[ $id ] = sanitize_textarea_field( (string) $in );
					break;
				case 'text':
					$values[ $id ] = sanitize_text_field( (string) $in );
					break;
				case 'hidden':
					$values[ $id ] = (string) ( $f['default'] ?? 1 );
					break;
				case 'heading':
				case 'content':
					break; // no value.
				default:
					$values[ $id ] = sanitize_text_field( (string) $in );
			}
		}

		foreach ( $cfg['fields'] as $f ) {
			$id = $f['id'];
			if ( ! isset( $values[ $id ] ) || is_array( $values[ $id ] ) ) { continue; }
			$v = (string) $values[ $id ];
			$transform = isset( $f['transform'] ) ? $f['transform'] : 'none';
			if ( 'digits' === $transform ) { $v = preg_replace( '/\D+/', '', $v ); }
			elseif ( 'uppercase' === $transform ) { $v = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $v, 'UTF-8' ) : strtoupper( $v ); }
			elseif ( 'lowercase' === $transform ) { $v = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v ); }
			$values[ $id ] = $v;
		}

		$active = $this->active_map( $cfg['fields'], $values );
		foreach ( $cfg['fields'] as $f ) {
			if ( empty( $f['required'] ) || empty( $active[ $f['id'] ] ) || in_array( $f['type'], array( 'heading', 'content', 'hidden' ), true ) ) {
				continue;
			}
			$v     = isset( $values[ $f['id'] ] ) ? $values[ $f['id'] ] : '';
			$empty = ( '' === $v || array() === $v || null === $v );
			if ( $empty ) {
				$errors[] = ! empty( $f['error_message'] ) ? $f['error_message'] : sprintf( __( '"%s" is required.', 'deli-multi-currency' ), $f['label'] );
			}
		}

		foreach ( $cfg['fields'] as $f ) {
			$id = $f['id'];
			if ( empty( $active[ $id ] ) || ! isset( $values[ $id ] ) || is_array( $values[ $id ] ) ) { continue; }
			$v = (string) $values[ $id ];
			if ( '' === $v ) { continue; }
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $v, 'UTF-8' ) : strlen( $v );
			$invalid = false;
			if ( ! empty( $f['min_length'] ) && $len < (int) $f['min_length'] ) { $invalid = true; }
			if ( ! empty( $f['max_length'] ) && $len > (int) $f['max_length'] ) { $invalid = true; }
			if ( ! empty( $f['pattern'] ) ) {
				$pattern = '~^(?:' . str_replace( '~', '\~', $f['pattern'] ) . ')$~u';
				$match = @preg_match( $pattern, $v ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false === $match ) {
					/* Compile failure (bad pattern, or invalid UTF-8 under /u). Treating that
					   as "invalid" hard-blocked add-to-cart for every customer with no way to
					   tell why, so skip the rule and log it instead. */
					if ( function_exists( 'wc_get_logger' ) ) {
						wc_get_logger()->warning(
							sprintf( 'Delicat_Builder_V9_Product_Fields_Calculator: pattern for field "%s" could not be evaluated and was skipped.', $f['id'] ),
							array( 'source' => 'delicat-builder-v9-calculator' )
						);
					}
				} elseif ( 1 !== $match ) {
					$invalid = true;
				}
			}
			if ( $invalid ) {
				$errors[] = ! empty( $f['error_message'] ) ? $f['error_message'] : sprintf( __( 'Please enter a valid value for "%s".', 'deli-multi-currency' ), $f['label'] );
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
			'active' => $active,
		);
	}

	public function compute_total( $product, $cfg, $values, $active ) {
		$base = (float) $product->get_price( 'edit' );
		$vars = array( 'base' => $base );

		// Per-product custom rate, referenced as {rate} (parent meta for variations).
		$meta_id      = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$custom       = get_post_meta( $meta_id, '_dmc_custom_rate', true );
		$vars['rate'] = ( '' !== $custom && is_numeric( $custom ) ) ? (float) $custom : 1.0;

		$sum = 0.0;
		foreach ( $cfg['fields'] as $f ) {
			$on               = ! empty( $active[ $f['id'] ] );
			$vars[ $f['id'] ] = $on ? $this->formula_value( $f, $values ) : 0.0;
			$sum             += $on ? $this->contribution( $f, $values, $base ) : 0.0;
		}
		$formula = isset( $cfg['formula'] ) ? trim( (string) $cfg['formula'] ) : '';
		if ( '' === $formula ) {
			$total = $base + $sum;
		} else {
			$eval = Delicat_Builder_V9_Product_Fields_Eval::evaluate_checked( $formula, $vars );
			if ( $eval['ok'] ) {
				$total = $eval['value'];
			} else {
				/* A malformed formula must never make the product free. Fall back to the
				   native price plus field contributions and leave a trace for the admin. */
				$total = $base + $sum;
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error(
						sprintf( 'Delicat_Builder_V9_Product_Fields_Calculator: formula rejected (%s), falling back to base price. Formula: %s', $eval['error'], $formula ),
						array( 'source' => 'delicat-builder-v9-calculator' )
					);
				}
			}
		}
		return round( max( 0.0, (float) $total ), 2 );
	}

	protected function display_labels( $cfg, $values, $active ) {
		$out = array();
		foreach ( $cfg['fields'] as $f ) {
			$id = $f['id'];
			if ( empty( $active[ $id ] ) || in_array( $f['type'], array( 'heading', 'content', 'hidden' ), true ) ) {
				continue;
			}
			$v = isset( $values[ $id ] ) ? $values[ $id ] : '';
			if ( '' === $v || array() === $v ) {
				continue;
			}
			if ( 'password' === $f['type'] ) {
				$out[ $f['label'] ] = __( 'Donnée sécurisée', 'deli-multi-currency' );
			} elseif ( in_array( $f['type'], array( 'select', 'radio' ), true ) ) {
				$opt                = $this->find_option( $f, $v );
				$out[ $f['label'] ] = $opt ? $opt['label'] : $v;
			} elseif ( 'checkbox' === $f['type'] ) {
				$labels = array();
				foreach ( (array) $v as $val ) {
					$opt      = $this->find_option( $f, $val );
					$labels[] = $opt ? $opt['label'] : $val;
				}
				$out[ $f['label'] ] = implode( ', ', $labels );
			} elseif ( 'toggle' === $f['type'] ) {
				$out[ $f['label'] ] = __( 'Yes', 'deli-multi-currency' );
			} else {
				$out[ $f['label'] ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Frontend rendering                                                     */
	/* --------------------------------------------------------------------- */

	public function assets() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$product_id = get_queried_object_id();
		if ( ! $product_id || ! $this->enabled( $product_id ) ) {
			return;
		}
		wp_enqueue_style( 'dmc-calc', DELICAT_BUILDER_V9_URL . 'modules/product-fields/assets/css/calc.css', array(), DELICAT_BUILDER_V9_VERSION );
		wp_enqueue_script( 'dmc-calc', DELICAT_BUILDER_V9_URL . 'modules/product-fields/assets/js/calc-front.js', array( 'jquery' ), DELICAT_BUILDER_V9_VERSION, true );

		$code = delicat_builder_v9_currency_runtime()->currencies->current();
		$c    = delicat_builder_v9_currency_runtime()->currencies->get( $code );
		wp_localize_script(
			'dmc-calc',
			'DMCCalc',
			array(
				'rate'     => delicat_builder_v9_currency_runtime()->currencies->rate( $code ),
				'symbol'   => $c ? $c['symbol'] : '',
				'decimals' => $c ? (int) $c['decimals'] : 2,
				'position' => $c ? $c['position'] : 'left',
			)
		);
	}

	public function render() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$cfg = $this->get_config( $product->get_id() );
		if ( ! $cfg ) {
			return;
		}
		$base        = (float) $product->get_price( 'edit' );
		$code        = delicat_builder_v9_currency_runtime()->currencies->current();
		$eff_rate    = delicat_builder_v9_currency_runtime()->price->no_convert( $product ) ? 1.0 : delicat_builder_v9_currency_runtime()->currencies->rate( $code );
		$custom      = get_post_meta( $product->get_id(), '_dmc_custom_rate', true );
		$custom_rate = ( '' !== $custom && is_numeric( $custom ) ) ? (float) $custom : 1.0;

		$d = isset( $cfg['design'] ) && is_array( $cfg['design'] ) ? $cfg['design'] : array();
		$d = wp_parse_args( $d, array( 'preset'=>'modern','card_radius'=>24,'field_radius'=>18,'field_height'=>58,'gap'=>14,'section_spacing'=>18,'quantity_spacing'=>18,'card_padding'=>14,'summary_padding'=>16,'card_bg'=>'#ffffff','field_bg'=>'#ffffff','text'=>'#111827','border'=>'#dbe2ea','accent'=>'#d9a441','help_bg'=>'#ffd43b','shadow'=>true,'floating_labels'=>false,'show_total'=>true,'total_label'=>'Total à payer','total_style'=>'card','total_radius'=>18,'total_bg'=>'#ffffff','help_glow'=>true,'purchase_studio'=>true,'show_product_name'=>true,'show_trust_chips'=>false,'animate_total'=>true ) );
		$style = '--dmc-card-radius:' . absint( $d['card_radius'] ) . 'px;--dmc-field-radius:' . absint( $d['field_radius'] ) . 'px;--dmc-field-height:' . absint( $d['field_height'] ) . 'px;--dmc-gap:' . absint( $d['gap'] ) . 'px;--dmc-section-spacing:' . absint( $d['section_spacing'] ) . 'px;--dmc-quantity-spacing:' . absint( $d['quantity_spacing'] ) . 'px;--dmc-card-padding:' . absint( $d['card_padding'] ) . 'px;--dmc-summary-padding:' . absint( $d['summary_padding'] ) . 'px;--dmc-card-bg:' . esc_attr( $d['card_bg'] ) . ';--dmc-field-bg:' . esc_attr( $d['field_bg'] ) . ';--dmc-text:' . esc_attr( $d['text'] ) . ';--dmc-border:' . esc_attr( $d['border'] ) . ';--dmc-accent:' . esc_attr( $d['accent'] ) . ';--dmc-help-bg:' . esc_attr( $d['help_bg'] ) . ';--dmc-total-radius:' . absint( $d['total_radius'] ) . 'px;--dmc-total-bg:' . esc_attr( $d['total_bg'] ) . ';';
		$classes = 'dmc-calc dmc-preset-' . sanitize_html_class( $d['preset'] ) . ' dmc-total-style-' . sanitize_html_class( $d['total_style'] );
		if ( ! empty( $d['shadow'] ) ) $classes .= ' dmc-has-shadow';
		if ( ! empty( $d['floating_labels'] ) ) $classes .= ' dmc-floating-labels';
		echo '<div class="' . esc_attr( $classes ) . '" style="' . esc_attr( $style ) . '" data-product-id="' . esc_attr( $product->get_id() ) . '" data-base="' . esc_attr( $base ) . '" data-rate="' . esc_attr( $eff_rate ) . '" data-customrate="' . esc_attr( $custom_rate ) . '" data-config="' . esc_attr( wp_json_encode( $this->public_config( $cfg ) ) ) . '">';
		foreach ( $cfg['fields'] as $f ) {
			$this->render_field( $f );
		}
		if ( ! empty( $d['show_total'] ) ) {
			/* RC51.3 — complete rebuild of the plan/total card.
			   Flat markup (icon + copy are direct grid children of .dmc-plan-summary,
			   the old .dmc-plan-glow/.dmc-plan-card-main wrappers broke the grid), one
			   state machine on the card (is-empty / is-checking / is-selected /
			   is-unavailable) driven by calc-front.js, Delicat gradient skin in CSS. */
			$is_variable    = $product->is_type( 'variable' );
			$in_stock       = $product->is_in_stock();
			$initial_total  = $is_variable ? '—' : wc_price( $base * $eff_rate, array( 'currency' => $code ) );
			$total_label    = isset( $d['total_label'] ) ? trim( (string) $d['total_label'] ) : '';
			if ( '' === $total_label || 'Total' === $total_label ) {
				$total_label = __( 'Total à payer', 'deli-multi-currency' );
			}
			$initial_name   = $is_variable ? __( 'Choisissez un plan', 'deli-multi-currency' ) : $product->get_name();
			$initial_status = $is_variable ? __( 'Sélection requise', 'deli-multi-currency' ) : ( $in_stock ? __( 'Disponible', 'deli-multi-currency' ) : __( 'Rupture de stock', 'deli-multi-currency' ) );
			$status_class   = $is_variable ? 'is-awaiting-selection' : ( $in_stock ? 'is-in-stock' : 'is-out-of-stock' );
			$card_state     = $is_variable ? 'is-empty' : ( $in_stock ? 'is-selected' : 'is-unavailable' );
			$kicker         = ( $is_variable && ! empty( $d['show_product_name'] ) ) ? $product->get_name() : __( 'Votre sélection', 'deli-multi-currency' );
			$studio_class   = ! empty( $d['purchase_studio'] ) ? ' dmc-purchase-studio' : '';
			$animate_class  = ! empty( $d['animate_total'] ) ? ' dmc-animate-total' : '';
			echo '<section class="dmc-plan-total-card dmc-woocommerce-summary dmc-delicat-card ' . esc_attr( $card_state . $studio_class . $animate_class ) . '" role="status" aria-live="polite" data-dmc-total-owner="product-fields" data-product-type="' . esc_attr( $product->get_type() ) . '">';
			echo '<div class="dmc-plan-summary" data-dmc-plan-summary>';
			/* SVG state icons keep inactive ✓ / ? / ! glyphs out of extracted
			 * document text while preserving the same visual state machine. */
			echo '<span class="dmc-plan-icon" aria-hidden="true">';
			echo '<span class="dmc-ico dmc-ico-check"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
			echo '<span class="dmc-ico dmc-ico-wait"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M9.5 9a3 3 0 1 1 4.9 2.3c-1.5 1.1-2.4 1.8-2.4 3.2" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round"/><circle cx="12" cy="18" r="1.3" fill="currentColor"/></svg></span>';
			echo '<span class="dmc-ico dmc-ico-alert"><svg viewBox="0 0 24 24" focusable="false" aria-hidden="true"><path d="M12 7v7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/><circle cx="12" cy="18" r="1.3" fill="currentColor"/></svg></span>';
			echo '</span>';
			echo '<span class="dmc-plan-copy">';
			echo '<small class="dmc-plan-kicker">' . esc_html( $kicker ) . '</small>';
			echo '<strong class="dmc-plan-name" data-dmc-plan-name>' . esc_html( $initial_name ) . '</strong>';
			echo '<em class="dmc-plan-status ' . esc_attr( $status_class ) . '" data-dmc-plan-status>' . esc_html( $initial_status ) . '</em>';
			echo '</span>';
			echo '</div>';
			echo '<div class="dmc-calc-total"><span class="dmc-calc-total-label">' . esc_html( $total_label ) . '</span> <span class="dmc-calc-total-value' . ( $is_variable ? ' is-pending' : '' ) . '">' . wp_kses_post( $initial_total ) . '</span></div>';
			echo '</section>';
		}
		echo '</div>';
		echo '<div class="dmc-help-modal" aria-hidden="true"><div class="dmc-help-backdrop"></div><div class="dmc-help-dialog" role="dialog" aria-modal="true" aria-labelledby="dmc-help-title"><button type="button" class="dmc-help-close" aria-label="Fermer">×</button><div class="dmc-help-media"></div><h3 id="dmc-help-title" class="dmc-help-title"></h3><div class="dmc-help-copy"></div></div></div>';
	}

	protected function public_config( $cfg ) {
		foreach ( $cfg['fields'] as &$field ) {
			if ( 'password' === ( $field['type'] ?? '' ) ) { $field['default'] = ''; }
		}
		unset( $field );
		return array(
			'fields'  => $cfg['fields'],
			'formula' => isset( $cfg['formula'] ) ? (string) $cfg['formula'] : '',
		);
	}

	protected function render_field( $f ) {
		$id   = $f['id'];
		$name = 'dmc_calc[' . $id . ']';
		$req  = ! empty( $f['required'] );
		$type = $f['type'];

		echo '<div class="dmc-field dmc-field-' . esc_attr( $type ) . '" data-field="' . esc_attr( $id ) . '">';

		// Content-only fields.
		if ( 'heading' === $type ) {
			echo '<h4 class="dmc-heading">' . esc_html( $f['label'] ) . '</h4>';
			if ( ! empty( $f['description'] ) ) {
				echo '<p class="dmc-field-desc">' . esc_html( $f['description'] ) . '</p>';
			}
			echo '</div>';
			return;
		}
		if ( 'content' === $type ) {
			echo '<div class="dmc-content">' . wp_kses_post( isset( $f['content'] ) ? $f['content'] : '' ) . '</div>';
			echo '</div>';
			return;
		}

		if ( ! empty( $f['label'] ) && 'hidden' !== $type ) {
			echo '<div class="dmc-label-row"><label class="dmc-field-label">' . esc_html( $f['label'] );
			if ( $req ) echo ' <span class="dmc-req">*</span>';
			echo '</label>';
			if ( ! empty( $f['help_enabled'] ) ) {
				$payload = array( 'title' => $f['help_title'] ?: $f['label'], 'text' => $f['help_text'] ?? '', 'image' => $f['help_image'] ?? '', 'video' => $f['help_video'] ?? '' );
				echo '<button type="button" class="dmc-help-trigger dmc-help-glow" data-help="' . esc_attr( wp_json_encode( $payload ) ) . '" aria-label="Aide : ' . esc_attr( $f['label'] ) . '">' . esc_html( $f['help_icon'] ?? '?' ) . '</button>';
			}
			echo '</div>';
		}

		$placeholder = isset( $f['placeholder'] ) ? $f['placeholder'] : '';
		$validation_attrs = '';
		if ( $req ) $validation_attrs .= ' required aria-required="true"';
		if ( ! empty( $f['min_length'] ) ) $validation_attrs .= ' minlength="' . absint( $f['min_length'] ) . '"';
		if ( ! empty( $f['max_length'] ) ) $validation_attrs .= ' maxlength="' . absint( $f['max_length'] ) . '"';
		if ( ! empty( $f['pattern'] ) ) $validation_attrs .= ' pattern="' . esc_attr( $f['pattern'] ) . '"';
		if ( ! empty( $f['error_message'] ) ) $validation_attrs .= ' data-error="' . esc_attr( $f['error_message'] ) . '"';
		if ( ! empty( $f['transform'] ) ) $validation_attrs .= ' data-transform="' . esc_attr( $f['transform'] ) . '"';

		// Mobile keyboard and autofill recommendations without changing stored values.
		$semantic = strtolower( trim( (string) ( ( $f['label'] ?? '' ) . ' ' . ( $f['placeholder'] ?? '' ) . ' ' . ( $f['id'] ?? '' ) ) ) );
		if ( 'email' === $type ) {
			$validation_attrs .= ' inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false"';
		} elseif ( 'password' === $type ) {
			$validation_attrs .= ' autocomplete="off" autocapitalize="none" spellcheck="false"';
		} elseif ( 'tel' === $type ) {
			$validation_attrs .= ' inputmode="tel" autocomplete="tel" autocapitalize="none" spellcheck="false"';
		} elseif ( 'url' === $type ) {
			$validation_attrs .= ' inputmode="url" autocomplete="url" autocapitalize="none" spellcheck="false"';
		} elseif ( false !== strpos( $semantic, 'whatsapp' ) || false !== strpos( $semantic, 'phone' ) || false !== strpos( $semantic, 'telephone' ) || false !== strpos( $semantic, 'téléphone' ) ) {
			$validation_attrs .= ' inputmode="tel" autocomplete="tel"';
		} elseif ( 'number' === $type || 'range' === $type || 'digits' === ( $f['transform'] ?? '' ) || false !== strpos( $semantic, 'player id' ) || false !== strpos( $semantic, 'uid' ) ) {
			$validation_attrs .= ' inputmode="numeric" autocomplete="off"';
		}

		$icon = isset( $f['icon'] ) ? trim( (string) $f['icon'] ) : '';
		if ( $icon && ! in_array( $type, array( 'radio','checkbox','toggle','hidden' ), true ) ) echo '<span class="dmc-control-shell dmc-has-icon"><span class="dmc-field-icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';

		switch ( $type ) {
			case 'number':
			case 'range':
				$suffix = ! empty( $f['suffix'] ) ? '<span class="dmc-suffix">' . esc_html( $f['suffix'] ) . '</span>' : '';
				echo '<span class="dmc-input-wrap">';
				printf(
					'<input type="%1$s" name="%2$s" class="dmc-input" placeholder="%7$s" value="%3$s" %4$s %5$s %6$s %8$s>',
					esc_attr( $type ),
					esc_attr( $name ),
					esc_attr( isset( $f['default'] ) ? $f['default'] : '' ),
					( isset( $f['min'] ) && '' !== $f['min'] ) ? 'min="' . esc_attr( $f['min'] ) . '"' : '',
					( isset( $f['max'] ) && '' !== $f['max'] ) ? 'max="' . esc_attr( $f['max'] ) . '"' : '',
					( isset( $f['step'] ) && '' !== $f['step'] ) ? 'step="' . esc_attr( $f['step'] ) . '"' : '',
					esc_attr( $placeholder ),
					$validation_attrs
				);
				echo $suffix; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				echo '</span>';
				break;

			case 'toggle':
				printf(
					'<label class="dmc-toggle"><input type="checkbox" name="%1$s" value="yes" class="dmc-input"> <span>%2$s</span></label>',
					esc_attr( $name ),
					esc_html( ! empty( $f['toggle_text'] ) ? $f['toggle_text'] : __( 'Yes', 'deli-multi-currency' ) )
				);
				break;

			case 'select':
				echo '<select name="' . esc_attr( $name ) . '" class="dmc-input" aria-label="' . esc_attr( $placeholder ?: $f['label'] ) . '" ' . $validation_attrs . '>';
				echo '<option value="">' . esc_html__( 'Choose…', 'deli-multi-currency' ) . '</option>';
				foreach ( (array) $f['options'] as $opt ) {
					printf( '<option value="%1$s">%2$s</option>', esc_attr( $opt['value'] ), esc_html( $opt['label'] ) );
				}
				echo '</select>';
				break;

			case 'radio':
			case 'checkbox':
				$this->render_choice( $f, $name );
				break;

			case 'text':
				printf( '<input type="text" name="%1$s" class="dmc-input" placeholder="%3$s" value="%2$s" %4$s>', esc_attr( $name ), esc_attr( isset( $f['default'] ) ? $f['default'] : '' ), esc_attr( $placeholder ), $validation_attrs );
				break;
			case 'textarea':
				printf( '<textarea name="%1$s" class="dmc-input" placeholder="%3$s" rows="3" %4$s>%2$s</textarea>', esc_attr( $name ), esc_textarea( isset( $f['default'] ) ? $f['default'] : '' ), esc_attr( $placeholder ), $validation_attrs );
				break;
			case 'email':
				printf( '<input type="email" name="%1$s" class="dmc-input" placeholder="%4$s" value="%2$s" %3$s>', esc_attr( $name ), esc_attr( isset( $f['default'] ) ? $f['default'] : '' ), $validation_attrs, esc_attr( $placeholder ) );
				break;
			case 'tel':
				printf( '<input type="tel" name="%1$s" class="dmc-input" placeholder="%4$s" value="%2$s" %3$s>', esc_attr( $name ), esc_attr( isset( $f['default'] ) ? $f['default'] : '' ), $validation_attrs, esc_attr( $placeholder ) );
				break;
			case 'password':
				printf( '<input type="password" name="%1$s" class="dmc-input" placeholder="%4$s" value="%2$s" autocomplete="new-password" spellcheck="false" autocapitalize="none" %3$s>', esc_attr( $name ), '', $validation_attrs, esc_attr( $placeholder ) );
				break;
			case 'url':
				printf( '<input type="url" name="%1$s" class="dmc-input" placeholder="%2$s" value="%4$s" %3$s>', esc_attr( $name ), esc_attr( $placeholder ?: 'https://' ), $validation_attrs, esc_attr( isset( $f['default'] ) ? $f['default'] : '' ) );
				break;
			case 'date':
				printf( '<input type="date" name="%1$s" class="dmc-input" value="%2$s" %3$s>', esc_attr( $name ), esc_attr( isset( $f['default'] ) ? $f['default'] : '' ), $validation_attrs );
				break;
			case 'hidden':
				printf( '<input type="hidden" name="%1$s" class="dmc-input" value="%2$s">', esc_attr( $name ), esc_attr( isset( $f['default'] ) ? $f['default'] : 1 ) );
				break;
		}
		if ( $icon && ! in_array( $type, array( 'radio','checkbox','toggle','hidden' ), true ) ) echo '</span>';

		if ( ! empty( $f['description'] ) ) {
			echo '<p class="dmc-field-desc">' . esc_html( $f['description'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * Render radio/checkbox options in the chosen appearance.
	 */
	protected function render_choice( $f, $name ) {
		$multiple   = ( 'checkbox' === $f['type'] );
		$input_type = $multiple ? 'checkbox' : 'radio';
		$input_name = $multiple ? $name . '[]' : $name;
		$appearance = isset( $f['appearance'] ) ? $f['appearance'] : 'default';

		$required_attr = ! empty( $f['required'] ) ? ' aria-required="true"' : '';
		echo '<div class="dmc-choices dmc-appearance-' . esc_attr( $appearance ) . '"' . $required_attr . '>';
		foreach ( (array) $f['options'] as $opt ) {
			$visual = '';
			if ( 'color' === $appearance && ! empty( $opt['color'] ) ) {
				$visual = '<span class="dmc-swatch-color" style="background:' . esc_attr( $opt['color'] ) . '"></span>';
			} elseif ( ( 'image' === $appearance || 'cards' === $appearance ) && ! empty( $opt['image'] ) ) {
				$visual = '<img class="dmc-swatch-img" src="' . esc_url( $opt['image'] ) . '" alt="">';
			}
			$desc = ( 'cards' === $appearance && ! empty( $opt['desc'] ) ) ? '<span class="dmc-card-desc">' . esc_html( $opt['desc'] ) . '</span>' : '';

			$native_required = ( ! empty( $f['required'] ) && 'radio' === $f['type'] ) ? ' required' : '';
			printf(
				'<label class="dmc-opt dmc-opt-%1$s"><input type="%2$s" name="%3$s" value="%4$s" class="dmc-input"%8$s>%5$s<span class="dmc-opt-label">%6$s</span>%7$s</label>',
				esc_attr( $appearance ),
				esc_attr( $input_type ),
				esc_attr( $input_name ),
				esc_attr( $opt['value'] ),
				$visual, // already escaped above.
				esc_html( $opt['label'] ),
				$desc, // already escaped above.
				$native_required
			);
		}
		echo '</div>';
	}

	/** Password-type fields are fulfillment secrets, never normal cart/order labels. */
	protected function secret_field_ids( $cfg, $values ) {
		$ids = array();
		foreach ( (array) ( $cfg['fields'] ?? array() ) as $f ) {
			if ( 'password' !== ( $f['type'] ?? '' ) ) { continue; }
			$id = (string) ( $f['id'] ?? '' );
			if ( '' !== $id && isset( $values[ $id ] ) && '' !== (string) $values[ $id ] ) { $ids[] = $id; }
		}
		return $ids;
	}

	protected function secret_fields( $cfg, $values ) {
		$out = array();
		foreach ( (array) ( $cfg['fields'] ?? array() ) as $f ) {
			if ( 'password' !== ( $f['type'] ?? '' ) ) { continue; }
			$id = (string) ( $f['id'] ?? '' );
			if ( '' === $id || ! isset( $values[ $id ] ) || '' === (string) $values[ $id ] ) { continue; }
			$plain = (string) $values[ $id ];
			$sealed = function_exists( 'delicat_builder_v9_seal_sensitive_value' ) ? delicat_builder_v9_seal_sensitive_value( $plain ) : '';
			if ( '' === $sealed ) { continue; }
			$out[ $id ] = array(
				'label' => sanitize_text_field( (string) ( $f['label'] ?? __( 'Information sécurisée', 'deli-multi-currency' ) ) ),
				'value' => $sealed,
			);
		}
		return $out;
	}

	protected function scrub_secret_values( $values, $secret_ids ) {
		/* Fail closed: a submitted secret is never copied into ordinary cart/session
		   values, even if the host's crypto backend unexpectedly fails. The cart
		   item is marked invalid below and checkout is blocked until resubmitted. */
		foreach ( (array) $secret_ids as $id ) {
			if ( isset( $values[ $id ] ) ) { $values[ $id ] = '__delicat_protected__'; }
		}
		return $values;
	}

	/* --------------------------------------------------------------------- */
	/* Cart / checkout / order                                                */
	/* --------------------------------------------------------------------- */

	public function validate( $passed, $product_id, $qty ) {
		$cfg = $this->get_config( $product_id );
		if ( ! $cfg ) {
			return $passed;
		}
		$raw    = isset( $_POST['dmc_calc'] ) ? wp_unslash( $_POST['dmc_calc'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->sanitize_values( $cfg, $raw );
		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $err ) {
				wc_add_notice( $err, 'error' );
			}
			return false;
		}
		if ( $this->secret_field_ids( $cfg, $result['values'] ) && ( ! function_exists( 'delicat_builder_v9_sensitive_crypto_available' ) || ! delicat_builder_v9_sensitive_crypto_available() ) ) {
			wc_add_notice( __( 'Le champ sécurisé ne peut pas être traité sur ce serveur pour le moment. Réessayez après vérification de la configuration de sécurité.', 'deli-multi-currency' ), 'error' );
			return false;
		}
		return $passed;
	}

	public function add_cart_item_data( $data, $product_id, $variation ) {
		$cfg = $this->get_config( $product_id );
		if ( ! $cfg ) {
			return $data;
		}
		$raw    = isset( $_POST['dmc_calc'] ) ? wp_unslash( $_POST['dmc_calc'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->sanitize_values( $cfg, $raw );

		// Price against the chosen variation when present, else the parent.
		$price_product = $variation ? wc_get_product( $variation ) : wc_get_product( $product_id );
		if ( ! $price_product instanceof WC_Product ) {
			$price_product = wc_get_product( $product_id );
		}
		$total = $this->compute_total( $price_product, $cfg, $result['values'], $result['active'] );
		$secret_ids = $this->secret_field_ids( $cfg, $result['values'] );
		$secrets = $this->secret_fields( $cfg, $result['values'] );
		$stored_values = $this->scrub_secret_values( $result['values'], $secret_ids );
		$secure_failed = count( $secrets ) !== count( $secret_ids );

		$data['dmc_calc'] = array(
			'labels' => $this->display_labels( $cfg, $result['values'], $result['active'] ),
			'values' => $stored_values,
			'active' => $result['active'],
			'vid'    => (int) $variation,
			'hash'   => md5( wp_json_encode( $stored_values ) . $total . (int) $variation ),
		);
		if ( ! empty( $secrets ) ) {
			$data['dmc_calc']['secrets'] = $secrets;
		}
		if ( $secure_failed ) {
			$data['dmc_calc']['invalid'] = true;
			$data['dmc_calc']['secure_error'] = true;
		}

		/* Not every add-to-cart path runs woocommerce_add_to_cart_validation the way
		   the classic form does (Store API and the app do not). Refuse to attach a
		   calculated price to a submission that failed its own validation. */
		if ( ! empty( $result['errors'] ) ) {
			$data['dmc_calc']['invalid'] = true;
			return $data;
		}

		// Only override the price when fields/formula actually change it; otherwise
		// the native (fixed or variation) price is left completely untouched.
		if ( $this->affects_price( $cfg ) ) {
			$data['dmc_calc']['total'] = $total;
		}
		return $data;
	}

	public function check_secure_cart_items() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return; }
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['dmc_calc']['secure_error'] ) ) {
				wc_add_notice( __( 'Une information sécurisée de cette commande n’a pas pu être chiffrée. Retirez le produit du panier puis ajoutez-le à nouveau.', 'deli-multi-currency' ), 'error' );
				break;
			}
		}
	}

	/**
	 * Does this config change the price at all? (Formula present, or any priced field.)
	 *
	 * @param array $cfg Config.
	 * @return bool
	 */
	protected function affects_price( $cfg ) {
		if ( ! empty( $cfg['formula'] ) && '' !== trim( (string) $cfg['formula'] ) ) {
			return true;
		}
		foreach ( $cfg['fields'] as $f ) {
			if ( ! in_array( $f['type'], self::PRICED_TYPES, true ) ) {
				continue;
			}
			if ( in_array( $f['type'], array( 'select', 'radio', 'checkbox' ), true ) ) {
				foreach ( (array) ( isset( $f['options'] ) ? $f['options'] : array() ) as $o ) {
					if ( 0.0 !== (float) ( isset( $o['price'] ) ? $o['price'] : 0 ) ) {
						return true;
					}
				}
			} elseif ( 0.0 !== (float) ( isset( $f['price'] ) ? $f['price'] : 0 ) ) {
				return true;
			}
		}
		return false;
	}

	public function get_cart_item_from_session( $item, $session ) {
		if ( isset( $session['dmc_calc'] ) ) {
			$item['dmc_calc'] = $session['dmc_calc'];
		}
		return $item;
	}

	public function apply_cart_prices( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! isset( $item['dmc_calc']['total'] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
				continue;
			}
			if ( ! empty( $item['dmc_calc']['invalid'] ) ) {
				continue; // never price a submission that failed validation.
			}

			$total = (float) $item['dmc_calc']['total'];

			/* Recompute from the stored field values. The old code replayed whatever
			   total was written at add-to-cart time, so a later price or formula change
			   kept charging the stale amount for the life of the session. */
			$product_id = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$cfg        = $product_id ? $this->get_config( $product_id ) : null;
			if ( $cfg && isset( $item['dmc_calc']['values'] ) && is_array( $item['dmc_calc']['values'] ) ) {
				$values = $item['dmc_calc']['values'];
				$active = isset( $item['dmc_calc']['active'] ) && is_array( $item['dmc_calc']['active'] )
					? $item['dmc_calc']['active']
					: $this->active_map( $cfg['fields'], $values );
				$fresh = $this->compute_total( $item['data'], $cfg, $values, $active );
				if ( $fresh > 0 ) {
					$total = $fresh;
				}
			}

			$item['data']->set_price( $total );
		}
	}

	public function cart_item_display( $item_data, $cart_item ) {
		if ( empty( $cart_item['dmc_calc']['labels'] ) ) {
			return $item_data;
		}
		foreach ( $cart_item['dmc_calc']['labels'] as $key => $val ) {
			$item_data[] = array(
				'key'   => $key,
				'value' => wc_clean( $val ),
			);
		}
		return $item_data;
	}

	public function save_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['dmc_calc']['secrets'] ) && is_array( $values['dmc_calc']['secrets'] ) ) {
			$item->add_meta_data( '_delicat_secure_fields', wp_json_encode( $values['dmc_calc']['secrets'] ), true );
		}
		if ( empty( $values['dmc_calc']['labels'] ) ) {
			return;
		}
		foreach ( $values['dmc_calc']['labels'] as $key => $val ) {
			$key = sanitize_text_field( $key );
			if ( '' === $key ) {
				continue;
			}
			/* A leading underscore makes WooCommerce treat the entry as hidden meta,
			   so a field labelled "_ID" disappeared from the order and the app. */
			if ( '_' === $key[0] ) {
				$key = ltrim( $key, '_' );
				if ( '' === $key ) {
					continue;
				}
			}
			$item->add_meta_data( $key, sanitize_text_field( $val ), true );
		}
	}
}
