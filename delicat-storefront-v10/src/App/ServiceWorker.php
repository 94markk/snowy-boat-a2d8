<?php
namespace Delicat\V10\App;

use Delicat\V10\Assets;
use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The service worker: what makes a repeat visit open like an installed app.
 *
 * -----------------------------------------------------------------------------
 * The rules it follows, and why each one is a rule
 * -----------------------------------------------------------------------------
 * 1. It caches only the shell, and only assets. Never a document, ever. A
 *    cached document is a page that can be shown to the wrong person, or shown
 *    after the price on it has changed. The speed of a repeat visit comes from
 *    the shell being local and the document being prerendered, which is faster
 *    than a cached document anyway and has none of the risk. V9 cached
 *    documents with a ten-minute freshness window and had to special-case
 *    maintenance mode, private paths, sensitive query strings and logout to
 *    stop it going wrong.
 *
 * 2. It precaches exactly the URLs the page requests. V9 precached
 *    `name.css?ver=9.2.0` while every page asked for `name.<hash>.css`, so the
 *    worker downloaded six files at install that nothing would ever ask for and
 *    every request still went to the network. Here both sides read the same
 *    manifest, and a test fails if they ever diverge.
 *
 * 3. A content-addressed asset is served from the cache without revalidating,
 *    because its name cannot change without its contents changing. Anything
 *    else is revalidated.
 *
 * 4. It never touches a request that is not a same-origin GET for a static
 *    asset. Not documents, not WooCommerce's AJAX, not the REST API, not
 *    anything with a nonce in it.
 */
final class ServiceWorker extends Module {

	public const PATH  = 'delicat-v10-sw.js';
	public const SCOPE = '/';

	public static function kinds(): array {
		return array( Context::KIND_FRONT );
	}

	public static function priority(): int {
		return 40;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
		add_action( 'wp_footer', array( $this, 'register_script' ), 20 );
	}

	public function add_rewrite(): void {
		add_rewrite_rule( '^' . self::PATH . '$', 'index.php?delicat_v10_sw=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function query_var( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = 'delicat_v10_sw';
		return $vars;
	}

	public function serve(): void {
		if ( ! get_query_var( 'delicat_v10_sw' ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		/*
		 * The worker script itself must be revalidated or a fix to it can take
		 * 24 hours to reach an installed app. Browsers cap service worker script
		 * caching at 24h precisely because of this, and no-cache removes even
		 * that.
		 */
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'Service-Worker-Allowed: ' . self::SCOPE );

		echo self::script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript assembled below from JSON-encoded values.
		exit;
	}

	/**
	 * Exactly the URLs the page will request, read from the same manifest
	 * Assets reads. Neither side holds a URL the other could disagree with.
	 *
	 * @return string[]
	 */
	public static function precache(): array {
		$urls = array();

		foreach ( array_keys( Assets::manifest() ) as $name ) {
			$urls[] = Assets::url( (string) $name );
		}

		/** @param string[] $urls */
		$urls = (array) apply_filters( 'delicat_v10_precache', $urls );

		return array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
	}

	private static function cache_name(): string {
		/* The version is in the cache name, so a plugin update discards the old
		 * cache wholesale instead of leaving entries to be individually
		 * invalidated. */
		return 'delicat-v10-' . preg_replace( '/[^A-Za-z0-9_.-]/', '-', DELICAT_V10_VERSION );
	}

	private static function script(): string {
		$cache    = wp_json_encode( self::cache_name() );
		$precache = wp_json_encode( self::precache(), JSON_UNESCAPED_SLASHES );

		return <<<JS
/* Delicat Storefront V10 service worker. Generated; do not edit. */
'use strict';

const CACHE = {$cache};
const PRECACHE = {$precache};

/* A content-addressed name cannot change without the name changing, so a hit is
   authoritative and needs no revalidation. Anything else is revalidated. */
const IMMUTABLE = /\.[0-9a-f]{12}\.(?:css|js)\$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      try {
        const cache = await caches.open(CACHE);
        /* One failed asset must not fail the whole install, or a single 404
           leaves the app with no offline shell at all. */
        await Promise.all(
          PRECACHE.map((url) => cache.add(new Request(url, { cache: 'reload' })).catch(() => {}))
        );
      } catch (error) {
        void error;
      }
      await self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const names = await caches.keys();
      await Promise.all(
        names.filter((n) => n.startsWith('delicat-v10-') && n !== CACHE).map((n) => caches.delete(n))
      );
      await self.clients.claim();
    })()
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') return;

  let url;
  try {
    url = new URL(request.url);
  } catch (error) {
    void error;
    return;
  }

  if (url.origin !== self.location.origin) return;

  /* Documents are never cached and never intercepted. The speed of a repeat
     visit comes from prerendering, which is faster than a cached document and
     cannot show one person's page to another. */
  if (request.mode === 'navigate' || request.destination === 'document') return;

  const isAsset = ['style', 'script', 'font', 'image'].includes(request.destination);
  if (!isAsset) return;

  event.respondWith(
    (async () => {
      const cache = await caches.open(CACHE);
      const hit = await cache.match(request);

      if (hit && (IMMUTABLE.test(url.pathname) || /[?&]ver=/.test(url.search))) return hit;

      const network = fetch(request)
        .then((response) => {
          const control = (response.headers.get('cache-control') || '').toLowerCase();
          if (response.ok && response.type !== 'opaque' && !/no-store|private/.test(control)) {
            cache.put(request, response.clone()).catch(() => {});
          }
          return response;
        })
        .catch(() => hit);

      /* Stale-while-revalidate for everything mutable: the cached copy is
         returned at once and a fresh one replaces it behind the request. */
      return hit || network;
    })()
  );
});
JS;
	}

	public function register_script(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		/*
		 * Registered after load, not during it. A service worker registration
		 * during page load competes for bandwidth with the page itself, and the
		 * first visit is the one that can least afford it.
		 */
		printf(
			'<script id="delicat-v10-sw">if("serviceWorker" in navigator){addEventListener("load",function(){navigator.serviceWorker.register(%s,{scope:%s}).catch(function(){});});}</script>' . "\n",
			wp_json_encode( home_url( '/' . self::PATH ) ),
			wp_json_encode( self::SCOPE )
		);
	}
}
