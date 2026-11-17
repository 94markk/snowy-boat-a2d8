<?php
/**
 * Delicat Builder V9 Pro — Navigation
 *
 * V10: the page markers of the Pro layer (generation meta, progress bar, route
 * body classes). Navigation itself belongs to the engine module.
 *
 * Requires PHP 8.3 (gated in the main plugin file).
 *
 * @package Delicat_Builder_V9_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'DBP_Nav', false ) ) {
	return;
}

final class DBP_Nav {

	const HANDLE = 'dbp-nav';

	/**
	 * V10: what is left of the Pro navigation layer. The engine module (assets/dist) is the only navigation; this
	 * class keeps the three page markers it reads and the CSS keys on. Removed: the JSON fragment endpoint
	 * (?dbp_nav=1 with the X-Delicat-Pro-Nav header, which no shipped script or app sends), its output transform,
	 * the stray-URL redirect and the dbp-nav.js fallback runtime (the engine dequeued it on every page it runs).
	 *
	 * @return void
	 */
	public static function boot() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_body_open', array( __CLASS__, 'progress_bar' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'document_generation' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/** A cached document from another build must reload the complete shell. */
	public static function document_generation() {
		echo '<meta name="dbp-generation" content="' . esc_attr( DBP_Kernel::generation() ) . '">';
	}

	/**
	 * Mark the document so CSS and the engine agree on what this route allows.
	 *
	 * V10: no « dbp-lowdata » class any more. It came from the Save-Data request header, and LiteSpeed does not
	 * vary its cache on that header, so the first visitor's connection decided the class for everyone served that
	 * cached copy. The client probe (app-tuning) sets delicat-slow-net and delicat-low-power on <html> before the
	 * first paint, for the visitor actually reading the page.
	 *
	 * @param array $classes Body classes.
	 * @return array
	 */
	public static function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		$route     = DBP_Kernel::route();
		$classes[] = 'dbp-app';
		$classes[] = 'dbp-route-' . sanitize_html_class( $route );

		if ( in_array( $route, DBP_Kernel::locked_routes(), true ) ) {
			$classes[] = 'dbp-locked';
		}

		return $classes;
	}

	/**
	 * Thin top progress bar. Rendered server-side so it is available before
	 * the engine parses and never causes a layout shift.
	 *
	 * @return void
	 */
	public static function progress_bar() {
		echo '<div class="dbp-progress" id="dbp-progress" aria-hidden="true"><i></i></div>';
	}
}
