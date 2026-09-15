<?php
namespace Delicat\V10\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Continuity with the store the merchant already has.
 *
 * V10 does not ask anyone to re-enter a configuration they spent months on.
 * Where V9 saved a choice that V10 still honours, it is read here and mapped
 * onto a V10 token - once, at the boundary, so no other class carries knowledge
 * of V9's option shapes and removing V9 later means deleting this one file.
 *
 * Nothing here writes. A V9 install is left exactly as it was found, so the two
 * can run side by side while the merchant compares them.
 */
final class V9Options {

	/** Options V10 reads. Anything not listed here is ignored. */
	private const DESIGN = 'delicat_builder_v9_design';
	private const SCHEMA = 'delicat_builder_v9_schema';
	private const MENU   = 'dsb_menu_builder_items';
	private const MENU_S = 'dsb_menu_builder_settings';
	private const HEADER = 'dsb8_beta2_header';

	/** @var array<string,mixed>|null */
	private static $design = null;

	public static function available(): bool {
		return array() !== self::design();
	}

	/** @return array<string,mixed> */
	public static function design(): array {
		if ( null !== self::$design ) {
			return self::$design;
		}
		$saved        = get_option( self::DESIGN, array() );
		self::$design = is_array( $saved ) ? $saved : array();
		return self::$design;
	}

	/**
	 * Map a V9 design setting onto the V10 token layer.
	 *
	 * Only colours and the corner radius carry across. V9's forty-odd
	 * per-component size settings do not: they existed because each component
	 * had its own scale, and V10 has one scale for all of them, so importing
	 * them would re-create exactly the fragmentation this rebuild removes.
	 *
	 * @param array<string,array<string,mixed>> $tokens
	 * @return array<string,array<string,mixed>>
	 */
	public static function apply_to_tokens( array $tokens ): array {
		$v9 = self::design();
		if ( array() === $v9 ) {
			return $tokens;
		}

		$map = array(
			'primary_color'       => 'brand',
			'secondary_color'     => 'accent',
			'surface_color'       => 'surface',
			'page_tint'           => 'ground',
			'homepage_heading'    => 'ink',
			'homepage_subtitle'   => 'ink-muted',
		);

		foreach ( $map as $from => $to ) {
			$value = isset( $v9[ $from ] ) ? (string) $v9[ $from ] : '';
			if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
				$tokens['color'][ $to ] = $value;
			}
		}

		/* The brand colour is the only one that also needs a dark counterpart:
		 * a saturated brand on a dark ground reads too heavy, so it is lifted
		 * rather than reused. Everything else keeps V10's dark ramp. */
		if ( isset( $tokens['color']['brand'] ) ) {
			$tokens['color-dark']['brand'] = self::lift( (string) $tokens['color']['brand'] );
		}

		if ( isset( $v9['radius'] ) && is_numeric( $v9['radius'] ) ) {
			$radius = max( 0, min( 40, (int) $v9['radius'] ) );
			$tokens['radius']['lg'] = $radius;
			$tokens['radius']['md'] = max( 0, $radius - 6 );
			$tokens['radius']['sm'] = max( 0, $radius - 10 );
			$tokens['radius']['xl'] = $radius + 8;
		}

		return $tokens;
	}

	/** Raise a hex colour's lightness for use on a dark ground. */
	private static function lift( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#8b7cff';
		}

		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$channel = hexdec( substr( $hex, $i * 2, 2 ) );
			$out    .= str_pad( dechex( (int) round( $channel + ( ( 255 - $channel ) * 0.28 ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * The merchant's homepage layout, as V9 stored it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function sections(): array {
		$schema = get_option( self::SCHEMA, array() );
		if ( ! is_array( $schema ) ) {
			return array();
		}
		$sections = $schema['sections'] ?? ( $schema['home']['sections'] ?? array() );
		return is_array( $sections ) ? array_values( $sections ) : array();
	}

	/**
	 * The merchant's navigation items.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function menu_items(): array {
		$items = get_option( self::MENU, array() );
		return is_array( $items ) ? array_values( $items ) : array();
	}

	/** @return array<string,mixed> */
	public static function menu_settings(): array {
		$settings = get_option( self::MENU_S, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/** @return array<string,mixed> */
	public static function header(): array {
		$header = get_option( self::HEADER, array() );
		return is_array( $header ) ? $header : array();
	}

	public static function flush(): void {
		self::$design = null;
	}
}
