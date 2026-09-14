<?php
/**
 * Customer fields on products: Player ID, server, e-mail of the account to
 * top up, a secret code, a choice that changes the price…
 *
 * Storage is the `_dmc_calc` product meta already used by the store's
 * previous storefront, in the same shape:
 *
 *   { "enabled": 1, "fields": [ { "id", "label", "type", "required",
 *     "placeholder", "description", "help_text", "help_image", "pattern",
 *     "min_length", "max_length", "min", "max", "price", "price_type",
 *     "options": [ { "label", "value", "price" } ], "content", "default" } ] }
 *
 * so every product configured before keeps its fields. The V9 formula
 * language is not ported: a field's price contribution is additive (fixed or
 * percent), which is what the store's products use.
 *
 * Values travel with the cart line, are shown in the cart, at checkout, in
 * the order e-mails and in the admin order screen, as visible line-item meta
 * named after the field's label. Password-type fields are stored encrypted
 * against nothing — they are kept out of the visible meta and written once
 * to `_delicat_secure_fields` on the line item, as before.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

const DS_FIELDS_META  = '_dmc_calc';
const DS_FIELDS_TYPES = array( 'text', 'number', 'email', 'tel', 'url', 'textarea', 'password', 'select', 'radio', 'checkbox', 'toggle', 'hidden', 'heading', 'content' );
const DS_FIELDS_INPUT = array( 'text', 'number', 'email', 'tel', 'url', 'textarea', 'password', 'select', 'radio', 'checkbox', 'toggle', 'hidden' );

/* ------------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------- */

/**
 * Field configuration of a product, or null when it has none.
 *
 * @return array{enabled:int,fields:array<int,array<string,mixed>>}|null
 */
function ds_fields_config( int $product_id ): ?array {
	$raw = get_post_meta( $product_id, DS_FIELDS_META, true );
	if ( empty( $raw ) ) {
		return null;
	}
	$cfg = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
	if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) || empty( $cfg['fields'] ) || ! is_array( $cfg['fields'] ) ) {
		return null;
	}
	$cfg['fields'] = array_values( array_filter( array_map( 'ds_fields_sanitize_field', $cfg['fields'] ) ) );
	return array() === $cfg['fields'] ? null : $cfg;
}

/**
 * Config for the product a cart line or product page refers to (variations
 * read their parent's fields).
 */
function ds_fields_config_for( WC_Product $product ): ?array {
	$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	return ds_fields_config( (int) $id );
}

/**
 * Normalise one field definition. Pure. Returns null for an unusable field.
 *
 * @param mixed $f Raw field.
 * @return array<string,mixed>|null
 */
function ds_fields_sanitize_field( $f ): ?array {
	if ( ! is_array( $f ) ) {
		return null;
	}
	$type = isset( $f['type'] ) ? strtolower( trim( (string) $f['type'] ) ) : 'text';
	if ( 'range' === $type ) {
		$type = 'number';
	}
	if ( 'date' === $type ) {
		$type = 'text';
	}
	if ( ! in_array( $type, DS_FIELDS_TYPES, true ) ) {
		$type = 'text';
	}
	$label = trim( (string) ( $f['label'] ?? '' ) );
	$id    = strtolower( trim( (string) ( $f['id'] ?? '' ) ) );
	$id    = (string) preg_replace( '/[^a-z0-9_]+/', '_', $id );
	$id    = trim( $id, '_' );
	if ( '' === $id ) {
		$id = trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', ds_normalize_term( $label ) ), '_' );
	}
	if ( '' === $id ) {
		return null;
	}
	if ( '' === $label && ! in_array( $type, array( 'hidden', 'content' ), true ) ) {
		$label = ucfirst( str_replace( '_', ' ', $id ) );
	}
	$options = array();
	if ( isset( $f['options'] ) && is_array( $f['options'] ) ) {
		foreach ( $f['options'] as $opt ) {
			if ( is_string( $opt ) ) {
				$opt = array( 'label' => $opt, 'value' => $opt );
			}
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$olabel = trim( (string) ( $opt['label'] ?? '' ) );
			$ovalue = trim( (string) ( $opt['value'] ?? $olabel ) );
			if ( '' === $ovalue && '' === $olabel ) {
				continue;
			}
			$options[] = array(
				'label' => '' !== $olabel ? $olabel : $ovalue,
				'value' => '' !== $ovalue ? $ovalue : $olabel,
				'price' => isset( $opt['price'] ) && is_numeric( $opt['price'] ) ? (float) $opt['price'] : 0.0,
			);
		}
	}
	$num = static function ( $v ) {
		return ( isset( $v ) && '' !== $v && is_numeric( $v ) ) ? (float) $v : '';
	};
	return array(
		'id'          => $id,
		'label'       => $label,
		'type'        => $type,
		'required'    => ! empty( $f['required'] ) ? 1 : 0,
		'placeholder' => trim( (string) ( $f['placeholder'] ?? '' ) ),
		'description' => trim( (string) ( $f['description'] ?? '' ) ),
		'help_text'   => trim( (string) ( $f['help_text'] ?? '' ) ),
		'help_image'  => trim( (string) ( $f['help_image'] ?? '' ) ),
		'pattern'     => trim( (string) ( $f['pattern'] ?? '' ) ),
		'min_length'  => isset( $f['min_length'] ) && '' !== $f['min_length'] ? (int) $f['min_length'] : '',
		'max_length'  => isset( $f['max_length'] ) && '' !== $f['max_length'] ? (int) $f['max_length'] : '',
		'min'         => $num( $f['min'] ?? '' ),
		'max'         => $num( $f['max'] ?? '' ),
		'price'       => (float) ( $num( $f['price'] ?? '' ) ?: 0 ),
		'price_type'  => ( isset( $f['price_type'] ) && 'percent' === $f['price_type'] ) ? 'percent' : 'fixed',
		'options'     => $options,
		'content'     => (string) ( $f['content'] ?? '' ),
		'default'     => trim( (string) ( $f['default'] ?? '' ) ),
	);
}

/**
 * Find an option by value.
 *
 * @param array<string,mixed> $field Field.
 * @return array{label:string,value:string,price:float}|null
 */
function ds_fields_option( array $field, string $value ): ?array {
	foreach ( $field['options'] as $opt ) {
		if ( (string) $opt['value'] === $value ) {
			return $opt;
		}
	}
	return null;
}

/* ------------------------------------------------------------------------
 * Validation & evaluation (pure — server is authoritative)
 * ---------------------------------------------------------------------- */

/**
 * Validate submitted values against a config.
 *
 * @param array<string,mixed> $cfg Config.
 * @param mixed               $raw Submitted `dmc_calc[...]` values.
 * @return array{values:array<string,mixed>,errors:string[]}
 */
function ds_fields_validate( array $cfg, $raw ): array {
	$raw    = is_array( $raw ) ? $raw : array();
	$values = array();
	$errors = array();

	foreach ( $cfg['fields'] as $f ) {
		$id   = $f['id'];
		$type = $f['type'];
		if ( ! in_array( $type, DS_FIELDS_INPUT, true ) ) {
			continue;
		}
		$in = $raw[ $id ] ?? '';
		if ( 'checkbox' !== $type && ! is_scalar( $in ) ) {
			$in = '';
		}
		$bad = static function () use ( &$errors, $f ): void {
			$errors[] = sprintf( 'Vérifiez le champ « %s ».', $f['label'] );
		};

		switch ( $type ) {
			case 'number':
				$in = trim( (string) $in );
				if ( '' === $in ) {
					$values[ $id ] = '';
					break;
				}
				if ( ! is_numeric( $in ) ) {
					$bad();
					$values[ $id ] = '';
					break;
				}
				$n = (float) $in;
				if ( ( '' !== $f['min'] && $n < (float) $f['min'] ) || ( '' !== $f['max'] && $n > (float) $f['max'] ) ) {
					$bad();
				}
				$values[ $id ] = $n;
				break;

			case 'checkbox':
				$clean = array();
				foreach ( (array) $in as $v ) {
					if ( is_scalar( $v ) && null !== ds_fields_option( $f, (string) $v ) ) {
						$clean[] = (string) $v;
					}
				}
				$values[ $id ] = array_values( array_unique( $clean ) );
				break;

			case 'select':
			case 'radio':
				$v             = trim( (string) $in );
				$values[ $id ] = null !== ds_fields_option( $f, $v ) ? $v : '';
				if ( '' !== $v && '' === $values[ $id ] ) {
					$bad();
				}
				break;

			case 'toggle':
				$values[ $id ] = ( '' !== (string) $in && 'no' !== $in && '0' !== (string) $in ) ? 'yes' : '';
				break;

			case 'email':
				$v = trim( (string) $in );
				if ( '' !== $v && ! filter_var( $v, FILTER_VALIDATE_EMAIL ) ) {
					$bad();
				}
				$values[ $id ] = $v;
				break;

			case 'url':
				$v = trim( (string) $in );
				if ( '' !== $v && ! filter_var( $v, FILTER_VALIDATE_URL ) ) {
					$bad();
				}
				$values[ $id ] = $v;
				break;

			case 'hidden':
				$values[ $id ] = $f['default'];
				break;

			default: /* text, tel, textarea, password */
				$v = 'textarea' === $type ? trim( (string) $in ) : trim( (string) preg_replace( '/[\r\n\t]+/', ' ', (string) $in ) );
				$v = mb_substr( $v, 0, 500, 'UTF-8' );
				if ( '' !== $v ) {
					if ( '' !== $f['min_length'] && mb_strlen( $v, 'UTF-8' ) < (int) $f['min_length'] ) {
						$bad();
					}
					if ( '' !== $f['max_length'] && mb_strlen( $v, 'UTF-8' ) > (int) $f['max_length'] ) {
						$bad();
					}
					if ( '' !== $f['pattern'] && ! (bool) @preg_match( '/^(?:' . str_replace( '/', '\/', $f['pattern'] ) . ')$/u', $v ) ) {
						$bad();
					}
				}
				$values[ $id ] = $v;
				break;
		}

		$empty = is_array( $values[ $id ] ) ? array() === $values[ $id ] : ( '' === (string) $values[ $id ] );
		if ( $f['required'] && $empty && 'hidden' !== $type ) {
			$errors[] = sprintf( 'Le champ « %s » est obligatoire.', $f['label'] );
		}
	}

	return array(
		'values' => $values,
		'errors' => array_values( array_unique( $errors ) ),
	);
}

/**
 * Price added by the fields, given the product's base price. Pure.
 *
 * @param array<string,mixed> $cfg    Config.
 * @param array<string,mixed> $values Validated values.
 */
function ds_fields_extra( array $cfg, array $values, float $base ): float {
	$unit = static function ( float $amount, string $ptype ) use ( $base ): float {
		return 'percent' === $ptype ? $base * $amount / 100 : $amount;
	};
	$sum = 0.0;
	foreach ( $cfg['fields'] as $f ) {
		$id  = $f['id'];
		$val = $values[ $id ] ?? '';
		switch ( $f['type'] ) {
			case 'number':
				$n = is_numeric( $val ) ? (float) $val : 0.0;
				if ( '' !== $f['min'] ) {
					$n = max( (float) $f['min'], $n );
				}
				if ( '' !== $f['max'] ) {
					$n = min( (float) $f['max'], $n );
				}
				$sum += $n * $unit( (float) $f['price'], $f['price_type'] );
				break;
			case 'toggle':
				$sum += 'yes' === $val ? $unit( (float) $f['price'], $f['price_type'] ) : 0.0;
				break;
			case 'select':
			case 'radio':
				$opt  = ds_fields_option( $f, (string) $val );
				$sum += $opt ? $unit( (float) $opt['price'], $f['price_type'] ) : 0.0;
				break;
			case 'checkbox':
				foreach ( (array) $val as $v ) {
					$opt  = ds_fields_option( $f, (string) $v );
					$sum += $opt ? $unit( (float) $opt['price'], $f['price_type'] ) : 0.0;
				}
				break;
			case 'hidden':
				$sum += $unit( (float) $f['price'], $f['price_type'] );
				break;
		}
	}
	return round( $sum, 2 );
}

/**
 * Does any field change the price?
 */
function ds_fields_priced( array $cfg ): bool {
	foreach ( $cfg['fields'] as $f ) {
		if ( (float) $f['price'] !== 0.0 ) {
			return true;
		}
		foreach ( $f['options'] as $opt ) {
			if ( (float) $opt['price'] !== 0.0 ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Does the config have a required input? (Cards then link to the product
 * instead of offering a one-tap add.)
 */
function ds_fields_has_required( ?array $cfg ): bool {
	if ( null === $cfg ) {
		return false;
	}
	foreach ( $cfg['fields'] as $f ) {
		if ( $f['required'] && in_array( $f['type'], DS_FIELDS_INPUT, true ) && 'hidden' !== $f['type'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Human-readable label => value pairs for display. Passwords are masked and
 * returned separately. Pure.
 *
 * @return array{labels:array<string,string>,secrets:array<string,string>}
 */
function ds_fields_labels( array $cfg, array $values ): array {
	$labels  = array();
	$secrets = array();
	foreach ( $cfg['fields'] as $f ) {
		$id = $f['id'];
		if ( ! in_array( $f['type'], DS_FIELDS_INPUT, true ) || 'hidden' === $f['type'] ) {
			continue;
		}
		$val = $values[ $id ] ?? '';
		if ( is_array( $val ) ? array() === $val : '' === (string) $val ) {
			continue;
		}
		$label = ltrim( $f['label'], '_' );
		if ( '' === $label ) {
			continue;
		}
		switch ( $f['type'] ) {
			case 'password':
				$secrets[ $label ] = (string) $val;
				$labels[ $label ]  = str_repeat( '•', 6 );
				break;
			case 'select':
			case 'radio':
				$opt              = ds_fields_option( $f, (string) $val );
				$labels[ $label ] = $opt ? $opt['label'] : (string) $val;
				break;
			case 'checkbox':
				$names = array();
				foreach ( (array) $val as $v ) {
					$opt     = ds_fields_option( $f, (string) $v );
					$names[] = $opt ? $opt['label'] : (string) $v;
				}
				$labels[ $label ] = implode( ', ', $names );
				break;
			case 'toggle':
				$labels[ $label ] = 'Oui';
				break;
			default:
				$labels[ $label ] = is_float( $val ) ? rtrim( rtrim( number_format( $val, 2, '.', '' ), '0' ), '.' ) : (string) $val;
		}
	}
	return array(
		'labels'  => $labels,
		'secrets' => $secrets,
	);
}

/* ------------------------------------------------------------------------
 * Storefront rendering
 * ---------------------------------------------------------------------- */

/**
 * Print the fields inside WooCommerce's add-to-cart form.
 */
function ds_fields_render(): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$cfg = ds_fields_config_for( $product );
	if ( null === $cfg ) {
		return;
	}
	$posted = isset( $_POST['dmc_calc'] ) && is_array( $_POST['dmc_calc'] ) ? wp_unslash( $_POST['dmc_calc'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- re-filling the form after a failed add-to-cart; values are escaped on output.
	echo '<div class="ds-fields" data-ds-fields>';
	foreach ( $cfg['fields'] as $f ) {
		ds_fields_render_field( $f, $posted[ $f['id'] ] ?? null );
	}
	echo '</div>';
}
add_action( 'woocommerce_before_add_to_cart_button', 'ds_fields_render', 20 );

/**
 * One field.
 *
 * @param array<string,mixed> $f     Field.
 * @param mixed               $value Re-fill value.
 */
function ds_fields_render_field( array $f, $value = null ): void {
	$id    = $f['id'];
	$type  = $f['type'];
	$name  = 'dmc_calc[' . $id . ']';
	$dom   = 'ds-field-' . $id;
	$value = null === $value ? $f['default'] : $value;

	if ( 'heading' === $type ) {
		echo '<div class="ds-field ds-field--heading"><h3 class="ds-field__heading">' . esc_html( $f['label'] ) . '</h3>';
		if ( '' !== $f['description'] ) {
			echo '<p class="ds-field__desc">' . esc_html( $f['description'] ) . '</p>';
		}
		echo '</div>';
		return;
	}
	if ( 'content' === $type ) {
		echo '<div class="ds-field ds-field--content">' . wp_kses_post( $f['content'] ) . '</div>';
		return;
	}
	if ( 'hidden' === $type ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $f['default'] ) . '">';
		return;
	}

	$req_attr = $f['required'] ? ' required aria-required="true"' : '';
	$attrs    = '';
	if ( '' !== $f['min_length'] ) {
		$attrs .= ' minlength="' . (int) $f['min_length'] . '"';
	}
	if ( '' !== $f['max_length'] ) {
		$attrs .= ' maxlength="' . (int) $f['max_length'] . '"';
	}
	if ( '' !== $f['pattern'] && in_array( $type, array( 'text', 'tel', 'password', 'url' ), true ) ) {
		$attrs .= ' pattern="' . esc_attr( $f['pattern'] ) . '"';
	}
	if ( '' !== $f['placeholder'] ) {
		$attrs .= ' placeholder="' . esc_attr( $f['placeholder'] ) . '"';
	}
	switch ( $type ) {
		case 'number':
			$attrs .= ' inputmode="decimal"';
			if ( '' !== $f['min'] ) {
				$attrs .= ' min="' . esc_attr( (string) $f['min'] ) . '"';
			}
			if ( '' !== $f['max'] ) {
				$attrs .= ' max="' . esc_attr( (string) $f['max'] ) . '"';
			}
			$attrs .= ' step="any"';
			break;
		case 'email':
			$attrs .= ' inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false"';
			break;
		case 'tel':
			$attrs .= ' inputmode="tel" autocomplete="tel"';
			break;
		case 'password':
			$attrs .= ' autocomplete="off" autocapitalize="none" spellcheck="false"';
			break;
		case 'text':
			$looks_numeric = '' !== $f['pattern'] && (bool) preg_match( '/^\[?0-9/', $f['pattern'] );
			$attrs        .= $looks_numeric ? ' inputmode="numeric"' : '';
			$attrs        .= ' autocapitalize="none" spellcheck="false" autocomplete="off"';
			break;
	}

	echo '<div class="ds-field ds-field--' . esc_attr( $type ) . '" data-field="' . esc_attr( $id ) . '">';
	echo '<div class="ds-field__row"><label class="ds-field__label" for="' . esc_attr( $dom ) . '">' . esc_html( $f['label'] );
	if ( $f['required'] ) {
		echo ' <span class="ds-field__req" aria-hidden="true">*</span>';
	}
	echo '</label>';
	if ( '' !== $f['help_text'] || '' !== $f['help_image'] ) {
		echo '<button type="button" class="ds-field__help" data-ds-help="' . esc_attr( $dom . '-help' ) . '" aria-label="' . esc_attr( 'Aide : ' . $f['label'] ) . '">' . ds_icon( 'info' ) . '<span>Aide</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</div>';

	switch ( $type ) {
		case 'textarea':
			echo '<textarea class="ds-input" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '" rows="3"' . $req_attr . $attrs . '>' . esc_textarea( (string) $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			break;
		case 'select':
			echo '<select class="ds-input" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '"' . $req_attr . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<option value="">' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : 'Choisir…' ) . '</option>';
			foreach ( $f['options'] as $opt ) {
				$suffix = (float) $opt['price'] !== 0.0 ? ' (+' . wp_strip_all_tags( wc_price( (float) $opt['price'] ) ) . ')' : '';
				echo '<option value="' . esc_attr( $opt['value'] ) . '"' . selected( (string) $value, (string) $opt['value'], false ) . '>' . esc_html( $opt['label'] . $suffix ) . '</option>';
			}
			echo '</select>';
			break;
		case 'radio':
		case 'checkbox':
			$multi = 'checkbox' === $type;
			echo '<div class="ds-choices" role="group" aria-labelledby="' . esc_attr( $dom ) . '">';
			foreach ( $f['options'] as $i => $opt ) {
				$checked = $multi ? in_array( (string) $opt['value'], array_map( 'strval', (array) $value ), true ) : (string) $value === (string) $opt['value'];
				$oid     = $dom . '-' . $i;
				$suffix  = (float) $opt['price'] !== 0.0 ? '<em>+' . wp_strip_all_tags( wc_price( (float) $opt['price'] ) ) . '</em>' : '';
				echo '<label class="ds-choice" for="' . esc_attr( $oid ) . '"><input type="' . ( $multi ? 'checkbox' : 'radio' ) . '" id="' . esc_attr( $oid ) . '" name="' . esc_attr( $name . ( $multi ? '[]' : '' ) ) . '" value="' . esc_attr( $opt['value'] ) . '"' . checked( $checked, true, false ) . ( ! $multi && $f['required'] ? ' required' : '' ) . '><span>' . esc_html( $opt['label'] ) . '</span>' . $suffix . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div>';
			break;
		case 'toggle':
			echo '<label class="ds-choice ds-choice--toggle" for="' . esc_attr( $dom ) . '"><input type="checkbox" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '" value="yes"' . checked( 'yes', (string) $value, false ) . '><span>' . esc_html( '' !== $f['placeholder'] ? $f['placeholder'] : 'Oui' ) . '</span>' . ( (float) $f['price'] !== 0.0 ? '<em>+' . esc_html( wp_strip_all_tags( wc_price( (float) $f['price'] ) ) ) . '</em>' : '' ) . '</label>';
			break;
		default:
			$html_type = in_array( $type, array( 'number', 'email', 'tel', 'password', 'url' ), true ) ? $type : 'text';
			echo '<input class="ds-input" type="' . esc_attr( $html_type ) . '" id="' . esc_attr( $dom ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"' . $req_attr . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( '' !== $f['description'] ) {
		echo '<p class="ds-field__desc">' . esc_html( $f['description'] ) . '</p>';
	}
	if ( '' !== $f['help_text'] || '' !== $f['help_image'] ) {
		/* No <form method="dialog"> here: the dialog sits inside WooCommerce's
		   add-to-cart form, and a nested form would close the outer one. */
		echo '<dialog class="ds-help" id="' . esc_attr( $dom . '-help' ) . '"><div class="ds-help__head"><strong>' . esc_html( $f['label'] ) . '</strong><button type="button" class="ds-iconbtn" data-ds-help-close aria-label="Fermer">' . ds_icon( 'close' ) . '</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( '' !== $f['help_text'] ) {
			echo '<p>' . nl2br( esc_html( $f['help_text'] ) ) . '</p>';
		}
		if ( '' !== $f['help_image'] ) {
			echo '<img src="' . esc_url( $f['help_image'] ) . '" alt="" loading="lazy">';
		}
		echo '</dialog>';
	}
	echo '</div>';
}

/* ------------------------------------------------------------------------
 * Cart & order
 * ---------------------------------------------------------------------- */

/**
 * Refuse an add-to-cart whose fields do not validate.
 *
 * @param bool $passed     Passed so far.
 * @param int  $product_id Product.
 * @return bool
 */
function ds_fields_validate_add( $passed, $product_id ) {
	$cfg = ds_fields_config( (int) $product_id );
	if ( null === $cfg ) {
		return $passed;
	}
	$raw    = isset( $_POST['dmc_calc'] ) ? wp_unslash( $_POST['dmc_calc'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's add-to-cart handler.
	$result = ds_fields_validate( $cfg, $raw );
	foreach ( $result['errors'] as $error ) {
		wc_add_notice( $error, 'error' );
	}
	return array() === $result['errors'] ? $passed : false;
}
add_filter( 'woocommerce_add_to_cart_validation', 'ds_fields_validate_add', 10, 2 );

/**
 * Attach validated values to the cart line.
 *
 * @param array<string,mixed> $data       Line data.
 * @param int                 $product_id Product.
 * @return array<string,mixed>
 */
function ds_fields_cart_item_data( $data, $product_id ) {
	$cfg = ds_fields_config( (int) $product_id );
	if ( null === $cfg ) {
		return $data;
	}
	$raw    = isset( $_POST['dmc_calc'] ) ? wp_unslash( $_POST['dmc_calc'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$result = ds_fields_validate( $cfg, $raw );
	$shown  = ds_fields_labels( $cfg, $result['values'] );
	$data['dmc_calc'] = array(
		'values'  => $result['values'],
		'labels'  => $shown['labels'],
		'secrets' => $shown['secrets'],
	);
	return $data;
}
add_filter( 'woocommerce_add_cart_item_data', 'ds_fields_cart_item_data', 10, 2 );

/**
 * Re-apply field prices on every totals calculation, from the stored values
 * and the CURRENT config, so a price change reaches open carts.
 */
function ds_fields_apply_prices( $cart ): void {
	if ( ! $cart instanceof WC_Cart ) {
		return;
	}
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['dmc_calc']['values'] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			continue;
		}
		$cfg = ds_fields_config( (int) $item['product_id'] );
		if ( null === $cfg || ! ds_fields_priced( $cfg ) ) {
			continue;
		}
		$base  = (float) $item['data']->get_price( 'edit' );
		$extra = ds_fields_extra( $cfg, (array) $item['dmc_calc']['values'], $base );
		$item['data']->set_price( max( 0.0, round( $base + $extra, wc_get_price_decimals() ) ) );
	}
}
add_action( 'woocommerce_before_calculate_totals', 'ds_fields_apply_prices', 20 );

/**
 * Show the values under the line in cart and checkout.
 *
 * @param array<int,array<string,string>> $item_data Rows.
 * @param array<string,mixed>             $cart_item Line.
 * @return array<int,array<string,string>>
 */
function ds_fields_cart_display( $item_data, $cart_item ) {
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
add_filter( 'woocommerce_get_item_data', 'ds_fields_cart_display', 10, 2 );

/**
 * Write the values to the order line, visible by label, secrets hidden.
 *
 * @param WC_Order_Item_Product $item   Line.
 * @param string                $key    Cart key.
 * @param array<string,mixed>   $values Cart line.
 */
function ds_fields_save_order_item( $item, $key, $values ): void {
	if ( empty( $values['dmc_calc'] ) ) {
		return;
	}
	if ( ! empty( $values['dmc_calc']['secrets'] ) ) {
		$item->add_meta_data( '_delicat_secure_fields', wp_json_encode( $values['dmc_calc']['secrets'] ), true );
	}
	foreach ( (array) ( $values['dmc_calc']['labels'] ?? array() ) as $label => $val ) {
		$label = ltrim( sanitize_text_field( (string) $label ), '_' );
		if ( '' === $label ) {
			continue;
		}
		$item->add_meta_data( $label, sanitize_text_field( (string) $val ), true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'ds_fields_save_order_item', 10, 3 );

/**
 * Secrets for staff: shown once on the admin order screen, never in e-mails.
 *
 * @param int                   $item_id Item id.
 * @param WC_Order_Item_Product $item    Item.
 */
function ds_fields_admin_secrets( $item_id, $item ): void {
	if ( ! $item instanceof WC_Order_Item_Product || ! current_user_can( 'edit_shop_orders' ) ) {
		return;
	}
	$json = $item->get_meta( '_delicat_secure_fields', true );
	if ( '' === $json ) {
		return;
	}
	$secrets = json_decode( (string) $json, true );
	if ( ! is_array( $secrets ) || array() === $secrets ) {
		return;
	}
	echo '<div class="ds-admin-secrets"><strong>Champs confidentiels :</strong> ';
	foreach ( $secrets as $label => $value ) {
		echo '<code>' . esc_html( (string) $label ) . ' : ' . esc_html( (string) $value ) . '</code> ';
	}
	echo '</div>';
}
add_action( 'woocommerce_after_order_itemmeta', 'ds_fields_admin_secrets', 10, 2 );

/* ------------------------------------------------------------------------
 * Admin: meta box on the product edit screen
 * ---------------------------------------------------------------------- */

function ds_fields_meta_box(): void {
	add_meta_box( 'ds-fields', 'Champs client (Player ID, compte, options…)', 'ds_fields_meta_box_render', 'product', 'normal', 'high' );
}
add_action( 'add_meta_boxes', 'ds_fields_meta_box' );

/**
 * @param WP_Post $post Product post.
 */
function ds_fields_meta_box_render( WP_Post $post ): void {
	$raw = get_post_meta( $post->ID, DS_FIELDS_META, true );
	$cfg = is_array( $raw ) ? $raw : ( is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null );
	if ( ! is_array( $cfg ) ) {
		$cfg = array( 'enabled' => 0, 'fields' => array() );
	}
	$cfg['fields'] = array_values( array_filter( array_map( 'ds_fields_sanitize_field', (array) ( $cfg['fields'] ?? array() ) ) ) );
	wp_nonce_field( 'ds_fields_save', 'ds_fields_nonce' );
	?>
	<div class="ds-fields-admin" data-ds-fields-admin>
		<p><label><input type="checkbox" name="ds_fields_enabled" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>> <strong>Demander des informations au client sur ce produit</strong></label></p>
		<p class="description">Exemple : un Player ID pour une recharge de jeu, l'e-mail du compte pour un abonnement, un numéro MonCash pour un échange. Les valeurs apparaissent dans le panier, la commande, les e-mails et l'écran de commande.</p>
		<div class="ds-fields-admin__list" data-ds-fields-list></div>
		<p class="ds-fields-admin__actions">
			<button type="button" class="button" data-ds-add-field>+ Ajouter un champ</button>
			<button type="button" class="button" data-ds-preset="player_id">+ Préréglage : Player ID</button>
			<button type="button" class="button" data-ds-preset="account_email">+ Préréglage : E-mail du compte</button>
			<button type="button" class="button" data-ds-preset="moncash">+ Préréglage : Numéro MonCash</button>
		</p>
		<textarea name="ds_fields_json" data-ds-fields-json hidden><?php echo esc_textarea( (string) wp_json_encode( $cfg['fields'] ) ); ?></textarea>
		<template data-ds-field-template>
			<div class="ds-fa-field" data-ds-field>
				<div class="ds-fa-field__head">
					<span class="ds-fa-field__handle" title="Glisser pour réordonner">⋮⋮</span>
					<strong data-ds-field-title>Nouveau champ</strong>
					<span class="ds-fa-field__spacer"></span>
					<button type="button" class="button-link" data-ds-move="up" aria-label="Monter">↑</button>
					<button type="button" class="button-link" data-ds-move="down" aria-label="Descendre">↓</button>
					<button type="button" class="button-link button-link-delete" data-ds-remove>Supprimer</button>
				</div>
				<div class="ds-fa-grid">
					<label>Libellé <input type="text" data-k="label" placeholder="Player ID"></label>
					<label>Identifiant technique <input type="text" data-k="id" placeholder="player_id"></label>
					<label>Type
						<select data-k="type">
							<option value="text">Texte</option>
							<option value="number">Nombre</option>
							<option value="email">E-mail</option>
							<option value="tel">Téléphone</option>
							<option value="url">Lien</option>
							<option value="textarea">Texte long</option>
							<option value="password">Secret (masqué)</option>
							<option value="select">Liste déroulante</option>
							<option value="radio">Choix unique</option>
							<option value="checkbox">Choix multiples</option>
							<option value="toggle">Case à cocher</option>
							<option value="hidden">Caché</option>
							<option value="heading">Titre de section</option>
							<option value="content">Bloc de texte</option>
						</select>
					</label>
					<label class="ds-fa-check"><input type="checkbox" data-k="required"> Obligatoire</label>
					<label>Texte indicatif <input type="text" data-k="placeholder" placeholder="Ex. 123456789"></label>
					<label>Note sous le champ <input type="text" data-k="description" placeholder="Vérifiez bien votre identifiant."></label>
					<label>Motif (regex, optionnel) <input type="text" data-k="pattern" placeholder="[0-9]{5,20}"></label>
					<label>Longueur min / max <span class="ds-fa-pair"><input type="number" data-k="min_length" min="0"><input type="number" data-k="max_length" min="0"></span></label>
					<label>Valeur min / max (nombre) <span class="ds-fa-pair"><input type="number" data-k="min" step="any"><input type="number" data-k="max" step="any"></span></label>
					<label>Supplément de prix <span class="ds-fa-pair"><input type="number" data-k="price" step="any" placeholder="0"><select data-k="price_type"><option value="fixed">montant</option><option value="percent">% du prix</option></select></span></label>
					<label>Valeur par défaut <input type="text" data-k="default"></label>
					<label class="ds-fa-wide">Options (une par ligne : <code>valeur | libellé | supplément</code>) <textarea data-k="options_text" rows="3" placeholder="asia | Serveur Asie&#10;eu | Serveur Europe | 50"></textarea></label>
					<label class="ds-fa-wide">Texte d'aide (bouton « Aide » sur la boutique) <textarea data-k="help_text" rows="2" placeholder="Ouvrez le jeu, touchez votre avatar : l'ID est sous votre pseudo."></textarea></label>
					<label class="ds-fa-wide">Image d'aide (URL) <span class="ds-fa-pair"><input type="url" data-k="help_image"><button type="button" class="button" data-ds-media>Choisir</button></span></label>
					<label class="ds-fa-wide">Contenu (type « Bloc de texte ») <textarea data-k="content" rows="2"></textarea></label>
				</div>
			</div>
		</template>
	</div>
	<?php
}

/**
 * Save the meta box.
 */
function ds_fields_save( int $post_id ): void {
	if ( ! isset( $_POST['ds_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( (string) wp_unslash( $_POST['ds_fields_nonce'] ) ), 'ds_fields_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_product', $post_id ) ) {
		return;
	}
	$enabled = ! empty( $_POST['ds_fields_enabled'] ) ? 1 : 0;
	$json    = isset( $_POST['ds_fields_json'] ) ? (string) wp_unslash( $_POST['ds_fields_json'] ) : '[]'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and sanitised field by field below.
	$fields  = json_decode( $json, true );
	$fields  = is_array( $fields ) ? array_values( array_filter( array_map( 'ds_fields_sanitize_field', $fields ) ) ) : array();
	foreach ( $fields as &$f ) {
		$f['label']       = sanitize_text_field( $f['label'] );
		$f['placeholder'] = sanitize_text_field( $f['placeholder'] );
		$f['description'] = sanitize_text_field( $f['description'] );
		$f['help_text']   = sanitize_textarea_field( $f['help_text'] );
		$f['help_image']  = esc_url_raw( $f['help_image'] );
		$f['content']     = wp_kses_post( $f['content'] );
		$f['default']     = sanitize_text_field( $f['default'] );
		foreach ( $f['options'] as &$o ) {
			$o['label'] = sanitize_text_field( $o['label'] );
			$o['value'] = sanitize_text_field( $o['value'] );
		}
		unset( $o );
	}
	unset( $f );

	if ( array() === $fields && 0 === $enabled ) {
		delete_post_meta( $post_id, DS_FIELDS_META );
		return;
	}
	update_post_meta(
		$post_id,
		DS_FIELDS_META,
		array(
			'enabled' => $enabled,
			'fields'  => $fields,
		)
	);
}
add_action( 'save_post_product', 'ds_fields_save' );
