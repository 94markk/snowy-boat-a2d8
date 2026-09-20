<?php

defined( 'ABSPATH' ) || exit;

/**
 * White-label layer.
 *
 * Everything an operator or a visitor can read goes through here so the upstream
 * supplier is never named outside of the plugin's own source code. The class is
 * static on purpose: brand decisions are needed in template-level code paths that
 * do not receive the settings object.
 */
final class DST2T_Brand {
	/** @var DST2T_Settings|null */
	private static $settings;

	/** Tokens scrubbed out of supplier-supplied catalog copy while stealth mode is on. */
	const SUPPLIER_TOKENS = array( 'shop2topup', 'shop 2 topup', 'shop2 topup', 's2t', 'shop2topup.com' );

	public static function boot( DST2T_Settings $settings ) {
		self::$settings = $settings;
	}

	public static function settings() {
		return self::$settings;
	}

	private static function option( $key, $default = '' ) {
		return self::$settings ? self::$settings->get( $key, $default ) : $default;
	}

	private static function flag( $key ) {
		return self::$settings ? self::$settings->enabled( $key ) : false;
	}

	/**
	 * Operator-visible name of the upstream service. Defaults to a neutral label so
	 * screenshots, exports, and shared admin sessions never disclose the supplier.
	 */
	public static function label() {
		$label = trim( (string) self::option( 'brand_label', '' ) );
		return '' !== $label ? $label : __( 'Top-Up Provider', 'delicat-shop2topup' );
	}

	/** Short label used inside tables and badges. */
	public static function short_label() {
		$label = self::label();
		return function_exists( 'mb_substr' ) && mb_strlen( $label ) > 18 ? mb_substr( $label, 0, 18 ) . '…' : $label;
	}

	public static function stealth() {
		return self::flag( 'stealth_mode' );
	}

	public static function masking() {
		return self::flag( 'mask_identifiers' );
	}

	/**
	 * Replaces the supplier's own name inside text that came from the supplier's
	 * catalog before that text is stored on a public WooCommerce product.
	 */
	public static function scrub( $text ) {
		$text = (string) $text;
		if ( '' === $text || ! self::stealth() ) {
			return $text;
		}
		$replacement = self::label();
		foreach ( self::SUPPLIER_TOKENS as $token ) {
			$text = preg_replace( '/\b' . preg_quote( $token, '/' ) . '\b/i', $replacement, $text );
		}
		$text = preg_replace( '#\bhttps?://[^\s"\']*shop2topup[^\s"\']*#i', '', (string) $text );
		return trim( preg_replace( '/\s{2,}/', ' ', (string) $text ) );
	}

	/**
	 * Masks an identifier down to its last few characters.
	 *
	 * @param string $value Raw identifier.
	 * @param int    $keep  Trailing characters kept in the clear.
	 */
	public static function mask_value( $value, $keep = 4 ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		$keep = max( 0, absint( $keep ) );
		$len  = strlen( $value );
		if ( $len <= $keep ) {
			return str_repeat( '•', $len );
		}
		return str_repeat( '•', min( 8, $len - $keep ) ) . substr( $value, -$keep );
	}

	/**
	 * Renders an identifier for the admin screen. While masking is on, the clear
	 * value is only written into a data attribute of an admin-only page and is
	 * revealed per row by an explicit operator action.
	 *
	 * @return string Escaped HTML.
	 */
	public static function secret_html( $value, $keep = 4, $classes = '' ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '<span class="dst2t-muted">—</span>';
		}
		$classes = trim( 'dst2t-secret ' . $classes );
		if ( ! self::masking() ) {
			return '<code class="' . esc_attr( $classes ) . '">' . esc_html( $value ) . '</code>';
		}

		return '<span class="' . esc_attr( $classes ) . '" data-dst2t-secret="' . esc_attr( $value ) . '">'
			. '<code class="dst2t-secret-value">' . esc_html( self::mask_value( $value, $keep ) ) . '</code>'
			. '<button type="button" class="button-link dst2t-reveal" aria-label="' . esc_attr__( 'Reveal identifier', 'delicat-shop2topup' ) . '">'
			. esc_html__( 'Reveal', 'delicat-shop2topup' ) . '</button></span>';
	}

	/** Neutral SKU prefix for imported catalog items. */
	public static function sku_prefix() {
		$prefix = strtolower( trim( (string) self::option( 'import_sku_prefix', '' ) ) );
		$prefix = preg_replace( '/[^a-z0-9\-_]/', '', $prefix );
		return '' !== $prefix ? $prefix : 'tu-';
	}
}
