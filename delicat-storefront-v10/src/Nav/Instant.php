<?php
namespace Delicat\V10\Nav;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The remaining latency: connection setup, font loading and back-forward cache.
 *
 * Each of these is worth more than it sounds. A cold connection to a font host
 * costs a DNS lookup, a TCP handshake and a TLS handshake before a single byte
 * of a font arrives, and a page that has been disqualified from the
 * back-forward cache is fully re-executed on Back rather than resumed - which
 * is the difference between Back being instantaneous and Back being a load.
 */
final class Instant extends Module {

	public static function priority(): int {
		return 32;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'preconnect' ), 1 );
		add_filter( 'style_loader_tag', array( $this, 'font_display' ), 10, 2 );
		add_action( 'send_headers', array( $this, 'protect_bfcache' ), 99 );
	}

	/**
	 * Warm the connections the first paint depends on.
	 *
	 * Only origins the page will certainly use: a preconnect to somewhere the
	 * page turns out not to need costs a connection the browser then has to
	 * hold open, which is why this list is short and derived rather than
	 * configured.
	 */
	public function preconnect(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		$origins = array();

		/* Uploads are usually same-origin, but a store on a CDN serves them
		 * from elsewhere and that origin is on the critical path for the
		 * largest image on the page. */
		$uploads = wp_get_upload_dir();
		$base    = (string) ( $uploads['baseurl'] ?? '' );
		$host    = (string) wp_parse_url( $base, PHP_URL_HOST );
		$self    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( '' !== $host && $host !== $self ) {
			$origins[] = ( wp_parse_url( $base, PHP_URL_SCHEME ) ?: 'https' ) . '://' . $host;
		}

		/** @param string[] $origins */
		$origins = (array) apply_filters( 'delicat_v10_preconnect', $origins );

		foreach ( array_unique( $origins ) as $origin ) {
			printf( '<link rel="preconnect" href="%s" crossorigin>' . "\n", esc_url( (string) $origin ) );
		}
	}

	/**
	 * Make every webfont swap rather than block.
	 *
	 * A font without font-display leaves its text invisible for up to three
	 * seconds on a slow connection while the browser waits. `swap` shows the
	 * fallback immediately and repaints when the font lands. The only cost is a
	 * reflow, and the token layer's type scale is sized so that reflow is small.
	 *
	 * @param string $tag
	 * @param string $handle
	 */
	public function font_display( $tag, $handle ): string {
		$tag = (string) $tag;

		if ( false === strpos( $tag, 'fonts.googleapis.com' ) ) {
			return $tag;
		}
		if ( false !== strpos( $tag, 'display=' ) ) {
			return $tag;
		}

		return (string) preg_replace_callback(
			'#href=([\'"])(https://fonts\.googleapis\.com/[^\'"]+)\1#i',
			static function ( array $m ): string {
				$url = $m[2] . ( false === strpos( $m[2], '?' ) ? '?' : '&' ) . 'display=swap';
				return 'href=' . $m[1] . esc_url( $url ) . $m[1];
			},
			$tag
		);
	}

	/**
	 * Keep storefront pages eligible for the back-forward cache.
	 *
	 * `no-store` on a main-frame document disqualifies it outright, and the
	 * customer gets a full re-execution on Back instead of the page resuming
	 * exactly as they left it. `no-cache` does not: it still forces
	 * revalidation with the server, which is all a personal page actually
	 * needs.
	 *
	 * V9 sent no-store on every logged-in page, so every Back in the store was
	 * a fresh load. This one header is worth more to the feel of the app than
	 * most of its JavaScript was.
	 */
	public function protect_bfcache(): void {
		$context = Context::instance();

		if ( ! $context->is_page_view() || headers_sent() ) {
			return;
		}

		if ( ! $context->is_private() ) {
			return;
		}

		header( 'Cache-Control: private, no-cache, max-age=0, must-revalidate' );
		header( 'Vary: Cookie', false );
	}
}
