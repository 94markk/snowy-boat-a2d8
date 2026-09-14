<?php
namespace Delicat\V10\Http;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Who may store this response, and for how long.
 *
 * -----------------------------------------------------------------------------
 * Two tiers, and the reason there are only two
 * -----------------------------------------------------------------------------
 * A storefront response is either the same for everyone or it is not. V9 had
 * four overlapping opinions about this - a security module, a cache module, a
 * Cloudflare module and an inline header call - which is how a page ended up
 * marked uncacheable for one layer and `public, max-age=60` for another, so the
 * CDN happily stored what the page cache had refused to.
 *
 *   shared    Identical for every visitor. A CDN and the page cache may store
 *             and reuse it. This is the fast path, and most of the store.
 *
 *   private   Contains, or could contain, one person's data. Stored only in
 *             that person's browser, and revalidated every time.
 *
 * There is deliberately no third "no-store" tier. `no-store` on a document
 * disqualifies it from the back-forward cache, which turns every Back press
 * into a full reload - a large, permanent cost paid to prevent a browser from
 * keeping a copy it is already allowed to keep. `no-cache` revalidates, which
 * is the actual requirement. Responses that genuinely must not be written to
 * disk at all - a payment page mid-flow - are WooCommerce's to declare, and
 * WooCommerce already does.
 */
final class Headers extends Module {

	public static function priority(): int {
		return 3;
	}

	public function register(): void {
		add_action( 'send_headers', array( $this, 'send' ), 1 );

		/*
		 * Tell the page caches that share this convention. Setting the constant
		 * is how LiteSpeed, W3 Total Cache, WP Rocket and most CDNs' plugins
		 * are told not to store a response, and it must be set before the
		 * response is generated, not after.
		 */
		add_action( 'template_redirect', array( $this, 'mark_uncacheable' ), 0 );
	}

	public function tier(): string {
		return Context::instance()->is_private() ? 'private' : 'shared';
	}

	public function send(): void {
		$context = Context::instance();

		if ( ! $context->is_page_view() || headers_sent() ) {
			return;
		}

		if ( 'private' === $this->tier() ) {
			/* Instant::protect_bfcache() states the document policy; this adds
			 * the part that matters to intermediaries, which is that the
			 * response varies by who asked for it. */
			header( 'Vary: Cookie', false );
			return;
		}

		$ttl = (int) apply_filters( 'delicat_v10_shared_max_age', 300 );
		$ttl = max( 0, min( 86400, $ttl ) );

		if ( 0 === $ttl ) {
			return;
		}

		/*
		 * stale-while-revalidate is the reason a shared page feels instant on a
		 * second visit: the CDN serves the copy it has immediately and fetches
		 * a fresh one behind the request, so nobody waits for the origin.
		 */
		header( sprintf( 'Cache-Control: public, max-age=%d, stale-while-revalidate=%d', $ttl, $ttl * 4 ) );
	}

	public function mark_uncacheable(): void {
		if ( 'private' !== $this->tier() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}
