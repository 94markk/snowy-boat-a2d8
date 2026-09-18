<?php
/**
 * Delicat Builder V9 Pro - Type & Token layer.
 *
 * The storefront shipped 275 distinct font sizes, 925 distinct hex colours and
 * 143 border-radius values across roughly 1 MB of CSS, and rendered section
 * titles in a serif while everything around them was sans. No single sheet
 * owned typography, so every module invented its own.
 *
 * This layer owns it. It does three things and nothing else:
 *
 *   1. Prints one token block and one normalisation sheet, enqueued after
 *      every other stylesheet so it wins without raising specificity.
 *   2. Marks the body so the sheet's selectors can scope to it, which means
 *      turning the layer off is a single class disappearing - no cascade of
 *      half-applied overrides.
 *   3. Fixes webfont delivery. Text must paint on a Haitian 3G link before
 *      any font file arrives.
 *
 * WHAT THIS LAYER DELIBERATELY DOES NOT DO
 * ----------------------------------------
 * It does not rewrite existing stylesheets, swap a sheet's media attribute,
 * or defer anything. pro.1 blanked the store by setting stylesheets to
 * media="print" with an inline onload that LiteSpeed's combiner dropped. A
 * technique that can leave the store unstyled is not used here at any cost.
 *
 * Requires PHP 8.3 (gated in the main plugin file).
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_Type', false ) ) {
	return;
}

final class DBP_Type {

	/**
	 * @return void
	 */
	public static function boot() {
		if ( is_admin() ) {
			return;
		}

		/* Priority sits above DBP_Assets (100000) so the token sheet is the
		 * last stylesheet registered on the page. */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 100100 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'style_loader_tag', array( __CLASS__, 'font_tag' ), 20, 4 );
		add_action( 'wp_head', array( __CLASS__, 'meta' ), 1 );
	}

	/**
	 * The sheet scopes every selector to body.dbp-type. One class controls
	 * the whole layer, and removing it restores the previous rendering
	 * exactly rather than leaving a partial override behind.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}
		$classes[] = 'dbp-type';

		return $classes;
	}

	/**
	 * @return void
	 */
	public static function enqueue() {
		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}

		$file = DELICAT_BUILDER_V9_DIR . 'pro/assets/dbp-type.css';
		if ( ! file_exists( $file ) ) {
			return;
		}

		wp_enqueue_style(
			'dbp-type',
			DBP_Kernel::asset_url( 'pro/assets/dbp-type.css' ),
			array(),
			DBP_Kernel::asset_version()
		);
	}

	/**
	 * Webfont delivery.
	 *
	 * The token sheet puts the platform font first (-apple-system on iOS,
	 * Roboto on Android), both already resident on the device. A Google Fonts
	 * request without display=swap still blocks text for up to three seconds
	 * while the browser waits for a face the page no longer needs first.
	 *
	 * Two corrections, both narrow:
	 *   - Always append display=swap when the URL lacks it.
	 *   - Drop the request entirely when the visitor asked for Save-Data.
	 *
	 * The stylesheet link itself is never converted to print media or
	 * preloaded with an inline onload. LiteSpeed combines stylesheets and
	 * does not carry inline handlers through the combined artefact.
	 *
	 * @param string $tag    Link tag.
	 * @param string $handle Style handle.
	 * @param string $href   Stylesheet URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public static function font_tag( $tag, $handle, $href, $media ) {
		unset( $handle, $media );

		if ( ! is_string( $href ) || '' === $href ) {
			return $tag;
		}
		if ( false === strpos( $href, 'fonts.googleapis.com' ) ) {
			return $tag;
		}

		if ( self::save_data() ) {
			return '';
		}

		if ( false !== strpos( $href, 'display=' ) ) {
			return $tag;
		}

		$separator = ( false === strpos( $href, '?' ) ) ? '?' : '&';
		$swapped   = $href . $separator . 'display=swap';

		return str_replace( $href, $swapped, $tag );
	}

	/**
	 * Did the visitor ask for less data?
	 *
	 * Read from the request header rather than a client hint written by
	 * JavaScript, because the decision has to be made before any script runs.
	 *
	 * @return bool
	 */
	private static function save_data() {
		if ( ! isset( $_SERVER['HTTP_SAVE_DATA'] ) ) {
			return false;
		}

		$value = strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_SAVE_DATA'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a literal below.

		return ( 'on' === $value );
	}

	/**
	 * Colour the browser chrome to match the token background so the address
	 * bar and the page stop disagreeing during a page switch. On a phone that
	 * mismatch is the most visible remaining seam between the store and a
	 * native app.
	 *
	 * @return void
	 */
	public static function meta() {
		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}

		echo '<meta name="theme-color" content="#eceef1" media="(prefers-color-scheme: light)">' . "\n";
		echo '<meta name="theme-color" content="#070914" media="(prefers-color-scheme: dark)">' . "\n";
	}
}
