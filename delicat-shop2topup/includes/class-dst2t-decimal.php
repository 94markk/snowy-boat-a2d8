<?php

defined( 'ABSPATH' ) || exit;

/**
 * Small fixed-point helper. Supplier money stays as decimal strings and is
 * never converted to a binary float for ordering or cost-guard comparisons.
 */
final class DST2T_Decimal {
	const SCALE = 6;

	public static function normalize( $value, $scale = self::SCALE ) {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^(?:0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $value, $matches ) ) {
			throw new InvalidArgumentException( 'Invalid positive decimal value.' );
		}

		$parts    = explode( '.', $value, 2 );
		$integer  = ltrim( $parts[0], '0' );
		$integer  = '' === $integer ? '0' : $integer;
		$fraction = isset( $parts[1] ) ? $parts[1] : '';
		$fraction = substr( str_pad( $fraction, $scale, '0' ), 0, $scale );

		return $integer . ( $scale > 0 ? '.' . $fraction : '' );
	}

	public static function compare( $left, $right, $scale = self::SCALE ) {
		$left  = self::digits( self::normalize( $left, $scale ) );
		$right = self::digits( self::normalize( $right, $scale ) );
		$max   = max( strlen( $left ), strlen( $right ) );
		$left  = str_pad( $left, $max, '0', STR_PAD_LEFT );
		$right = str_pad( $right, $max, '0', STR_PAD_LEFT );

		return strcmp( $left, $right ) < 0 ? -1 : ( strcmp( $left, $right ) > 0 ? 1 : 0 );
	}

	/**
	 * Adds a percentage expressed as a decimal string, rounding the result up
	 * to the nearest micro-unit so the configured guard is never stricter due
	 * only to truncation.
	 */
	public static function add_percent( $amount, $percent ) {
		$scaled = self::to_int( $amount );
		$basis  = self::percent_basis_points( $percent );

		if ( $scaled > (int) floor( PHP_INT_MAX / max( 10000, 10000 + $basis ) ) ) {
			throw new OverflowException( 'Decimal value is too large.' );
		}

		$numerator = $scaled * ( 10000 + $basis );
		$result    = intdiv( $numerator + 9999, 10000 );
		return self::from_int( $result );
	}

	private static function percent_basis_points( $percent ) {
		$normalized = self::normalize( $percent, 2 );
		$digits     = self::digits( $normalized );
		if ( strlen( $digits ) > 6 ) {
			throw new OverflowException( 'Percentage is too large.' );
		}
		return (int) $digits;
	}

	private static function to_int( $amount ) {
		$digits  = self::digits( self::normalize( $amount ) );
		$maximum = (string) PHP_INT_MAX;
		// Compare like with like: strcmp on unequal lengths is meaningless here.
		if ( strlen( $digits ) > strlen( $maximum ) || ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) ) {
			throw new OverflowException( 'Decimal value is too large.' );
		}
		return (int) $digits;
	}

	private static function from_int( $value ) {
		$digits = str_pad( (string) absint( $value ), self::SCALE + 1, '0', STR_PAD_LEFT );
		return substr( $digits, 0, -self::SCALE ) . '.' . substr( $digits, -self::SCALE );
	}

	private static function digits( $normalized ) {
		return ltrim( str_replace( '.', '', $normalized ), '0' ) ?: '0';
	}
}
