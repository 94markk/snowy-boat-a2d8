<?php
/**
 * Delicat Builder V9 Pro — Navigation
 *
 * One navigation engine. V9 shipped three (shell-nav.js, navigation.js and the
 * shell's own handler) plus two prefetchers and a speculation-rules block, all
 * binding the same link clicks. That race is the source of both the lag and the
 * intermittent dead taps. Pro replaces all of them with a single delegated
 * engine and a server-side fragment endpoint.
 *
 * The fragment endpoint renders the page through WordPress's normal template
 * path and returns only what changed, so a page switch costs a fraction of a
 * full document on a slow connection.
 *
 * PHP 7.4 compatible.
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

	/** @var bool */
	private static $capturing = false;

	/**
	 * @return void
	 */
	public static function boot() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'template_redirect', array( __CLASS__, 'maybe_capture' ), PHP_INT_MAX );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_body_open', array( __CLASS__, 'progress_bar' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Mark the document so CSS and the engine agree on what this route allows.
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

		$connection = DBP_Kernel::connection();
		if ( ! empty( $connection['slow'] ) ) {
			$classes[] = 'dbp-lowdata';
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
		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}

		echo '<div class="dbp-progress" id="dbp-progress" aria-hidden="true"><i></i></div>';
	}

	/**
	 * Is this request allowed to be answered as a fragment?
	 *
	 * @return bool
	 */
	private static function fragment_allowed() {
		if ( ! DBP_Kernel::is_fragment_request() ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_embed() || is_404() ) {
			return false;
		}
		if ( ( class_exists( 'Delicat_Builder_V9_Security', false ) && ! Delicat_Builder_V9_Security::navigation_request_allowed() ) || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( in_array( DBP_Kernel::route(), DBP_Kernel::locked_routes(), true ) ) {
			return false;
		}

		/* Same-origin only. A fragment carries the same markup a page does, so
		 * it must never be readable as a cross-site request. */
		if ( isset( $_SERVER['HTTP_SEC_FETCH_SITE'] ) ) {
			$site = sanitize_key( wp_unslash( (string) $_SERVER['HTTP_SEC_FETCH_SITE'] ) );
			if ( '' !== $site && 'same-origin' !== $site ) {
				return false;
			}
		} elseif ( isset( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) );
			$host    = wp_parse_url( $referer, PHP_URL_HOST );
			if ( is_string( $host ) && strtolower( $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Begin capturing the rendered page so it can be reduced to a fragment.
	 *
	 * @return void
	 */
	public static function maybe_capture() {
		if ( self::$capturing ) {
			return;
		}
		self::redirect_stray_fragment_url();
		if ( ! self::fragment_allowed() ) {
			if ( DBP_Kernel::is_fragment_request() ) {
				/* A fragment fetch the server declines (locked route, cross-site)
				 * falls back to the full document; the engine then navigates
				 * normally. That document must never be stored under the
				 * fragment URL by any cache layer. */
				if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
				do_action( 'litespeed_control_set_nocache', 'Delicat navigation fallback document' );
				if ( ! headers_sent() ) { nocache_headers(); }
			}
			return;
		}

		self::$capturing = true;
		// JSON is not an HTML optimization target. LiteSpeed otherwise appends
		// its HTML footer to our JSON and response.json() fails on every click.
		if ( ! defined( 'LITESPEED_NO_OPTM' ) ) { define( 'LITESPEED_NO_OPTM', true ); }
		add_filter( 'litespeed_comment', '__return_false', PHP_INT_MAX );

		/*
		 * Fragments are never stored by any cache layer.
		 *
		 * pro.16 tried to make them cacheable like documents. That was wrong on this
		 * stack and delivered nothing: Delicat_Builder_V9_Security::protect_private_response()
		 * runs on template_redirect at priority 0 and already treats the bare presence
		 * of ?dbp_nav as private — it defines DONOTCACHEPAGE and calls
		 * litespeed_control_set_nocache — and Server_Engine::prepare_cache_policy()
		 * does the same at priority 3 because $_GET is non-empty on exactly these
		 * requests. LiteSpeed honours DONOTCACHEPAGE, so it would never have stored a
		 * fragment whatever header we chose, while `Cache-Control: public, max-age=60`
		 * still reached Cloudflare on a zone this plugin purges wholesale (i.e. one
		 * that caches HTML). The Vary header it leaned on is honoured by neither
		 * LiteSpeed nor Cloudflare, so ?dbp_nav=1 was a single cache key for a JSON
		 * body, a 302, and every currency and low-data variant of the page.
		 *
		 * Making fragments genuinely cacheable is worth doing, but it has to start at
		 * that priority-0 gate and at the "$_GET is non-empty" rule in
		 * Security::public_cache_allowed(), not with a header written over the top of
		 * both of them.
		 */
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Delicat navigation response' );
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Robots-Tag: noindex, noarchive' );
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'X-LiteSpeed-Cache-Control: no-cache', true );
		}

		ob_start( array( __CLASS__, 'transform' ) );
	}

	/**
	 * `?dbp_nav=1` is the engine's fragment URL, never a page a person should
	 * land on. Without the engine header it cannot be a fragment fetch, so it
	 * is answered with a redirect to the clean URL rather than a document.
	 *
	 * @return void
	 */
	private static function redirect_stray_fragment_url() {
		if ( is_admin() || wp_doing_ajax() || headers_sent() ) {
			return;
		}
		if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
			return;
		}
		if ( ! isset( $_GET['dbp_nav'] ) || isset( $_SERVER['HTTP_X_DELICAT_PRO_NAV'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
			return;
		}
		$clean = remove_query_arg( 'dbp_nav' );
		if ( ! is_string( $clean ) || '' === $clean ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		do_action( 'litespeed_control_set_nocache', 'Delicat stray fragment URL' );
		nocache_headers();
		wp_safe_redirect( $clean, 302 );
		exit;
	}


	/**
	 * Reduce a full rendered document to the parts that change between pages.
	 *
	 * @param string $html Full page HTML.
	 * @return string JSON payload.
	 */
	public static function transform( $html ) {
		$html = (string) $html;

		$main = self::extract_element( $html, 'main' );
		if ( '' === $main ) {
			/* No recognised content root: tell the client to load normally
			 * rather than guessing at a swap that would drop content. */
			return wp_json_encode( array( 'full' => true, 'reason' => 'no_main' ) );
		}

		$payload = array(
			'ok'         => true,
			'url'        => self::current_url(),
			'route'      => DBP_Kernel::route(),
			'title'      => self::extract_title( $html ),
			'bodyClass'  => self::extract_body_class( $html ),
			'main'       => $main,
			'styles'     => self::extract_styles( $html ),
			'scripts'    => self::extract_scripts( $html ),
			'inline'     => self::extract_inline( $html ),
			'canonical'  => self::extract_canonical( $html ),
			'locked'     => in_array( DBP_Kernel::route(), DBP_Kernel::locked_routes(), true ),
			'generation' => DBP_Kernel::generation(),
		);

		$json = wp_json_encode( $payload );

		return is_string( $json ) ? $json : '{"full":true,"reason":"encode"}';
	}

	/**
	 * Current request URL, normalised.
	 *
	 * @return string
	 */
	private static function current_url() {
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';

		return home_url( $request );
	}

	/**
	 * Extract one balanced element by tag name.
	 *
	 * Written as a tag-balanced scan rather than a regular expression: a
	 * greedy pattern breaks the moment a nested element of the same name
	 * appears, and a lazy one truncates the page.
	 *
	 * @param string $html Document HTML.
	 * @param string $tag  Tag name.
	 * @return string Outer HTML, or empty string.
	 */
	private static function extract_element( $html, $tag ) {
		$tag   = strtolower( (string) $tag );
		$open  = '<' . $tag;
		$close = '</' . $tag;

		$start = stripos( $html, $open );
		if ( false === $start ) {
			return '';
		}

		/* Confirm this is the tag and not a longer name sharing the prefix. */
		$after = substr( $html, $start + strlen( $open ), 1 );
		if ( '' !== $after && false === strpos( " \t\r\n>/", $after ) ) {
			return '';
		}

		$depth  = 0;
		$cursor = $start;
		$length = strlen( $html );

		while ( $cursor < $length ) {
			$next_open  = stripos( $html, $open, $cursor );
			$next_close = stripos( $html, $close, $cursor );

			if ( false === $next_close ) {
				return '';
			}

			if ( false !== $next_open && $next_open < $next_close ) {
				$peek = substr( $html, $next_open + strlen( $open ), 1 );
				if ( '' === $peek || false !== strpos( " \t\r\n>/", $peek ) ) {
					$depth++;
				}
				$cursor = $next_open + strlen( $open );
				continue;
			}

			$depth--;
			$end = strpos( $html, '>', $next_close );
			if ( false === $end ) {
				return '';
			}

			if ( 0 === $depth ) {
				return substr( $html, $start, ( $end + 1 ) - $start );
			}

			$cursor = $end + 1;
		}

		return '';
	}

	/**
	 * @param string $html Document HTML.
	 * @return string
	 */
	private static function extract_title( $html ) {
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $match ) ) {
			return trim( wp_specialchars_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES ) );
		}

		return '';
	}

	/**
	 * @param string $html Document HTML.
	 * @return string
	 */
	private static function extract_body_class( $html ) {
		if ( preg_match( '#<body[^>]*\sclass=("|\')(.*?)\1#is', $html, $match ) ) {
			return trim( (string) $match[2] );
		}

		return '';
	}

	/**
	 * @param string $html Document HTML.
	 * @return string
	 */
	private static function extract_canonical( $html ) {
		if ( preg_match( '#<link[^>]+rel=("|\')canonical\1[^>]*href=("|\')(.*?)\2#is', $html, $match ) ) {
			return esc_url_raw( (string) $match[3] );
		}

		return '';
	}

	/**
	 * Stylesheet URLs referenced by the rendered page, in document order.
	 *
	 * @param string $html Document HTML.
	 * @return array
	 */
	private static function extract_styles( $html ) {
		$out = array();

		if ( preg_match_all( '#<link[^>]+rel=("|\')stylesheet\1[^>]*>#is', $html, $links ) ) {
			foreach ( $links[0] as $tag ) {
				if ( preg_match( '#href=("|\')(.*?)\1#is', $tag, $href ) ) {
					$url = esc_url_raw( html_entity_decode( (string) $href[2], ENT_QUOTES, 'UTF-8' ) );
					if ( '' !== $url ) {
						$id    = '';
						if ( preg_match( '#id=("|\')(.*?)\1#is', $tag, $ident ) ) {
							$id = sanitize_text_field( (string) $ident[2] );
						}
						$out[] = array( 'id' => $id, 'href' => $url );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * External script URLs referenced by the rendered page, in document order.
	 *
	 * @param string $html Document HTML.
	 * @return array
	 */
	private static function extract_scripts( $html ) {
		$out = array();

		if ( preg_match_all( '#<script[^>]+src=("|\')(.*?)\1[^>]*>#is', $html, $tags, PREG_SET_ORDER ) ) {
			foreach ( $tags as $tag ) {
				$url = esc_url_raw( html_entity_decode( (string) $tag[2], ENT_QUOTES, 'UTF-8' ) );
				if ( '' === $url ) {
					continue;
				}
				$id = '';
				if ( preg_match( '#id=("|\')(.*?)\1#is', $tag[0], $ident ) ) {
					$id = sanitize_text_field( (string) $ident[2] );
				}
				$out[] = array( 'id' => $id, 'src' => $url );
			}
		}

		return $out;
	}

	/**
	 * Inline configuration blocks the next page's scripts depend on.
	 *
	 * WordPress emits localisation as `handle-js-extra` / `handle-js-before`
	 * blocks. Swapping content without carrying these forward is why V9's
	 * shell left product surfaces half-initialised.
	 *
	 * @param string $html Document HTML.
	 * @return array
	 */
	private static function extract_inline( $html ) {
		$out = array();

		if ( ! preg_match_all( '#<script([^>]*)>(.*?)</script>#is', $html, $tags, PREG_SET_ORDER ) ) {
			return $out;
		}

		foreach ( $tags as $tag ) {
			$attributes = (string) $tag[1];
			$body       = (string) $tag[2];

			if ( false !== stripos( $attributes, 'src=' ) || '' === trim( $body ) ) {
				continue;
			}
			if ( preg_match( '#type=("|\')(?!text/javascript|application/javascript)#i', $attributes ) ) {
				continue;
			}

			$id = '';
			if ( preg_match( '#id=("|\')(.*?)\1#is', $attributes, $ident ) ) {
				$id = sanitize_text_field( (string) $ident[2] );
			}

			/* Only carry configuration blocks, never arbitrary inline code. */
			$is_config = ( '' !== $id && (
				false !== strpos( $id, '-js-extra' )
				|| false !== strpos( $id, '-js-before' )
				|| 0 === strpos( $id, 'dbp-' )
				|| 0 === strpos( $id, 'delicat-' )
			) );

			if ( ! $is_config ) {
				continue;
			}

			$out[] = array( 'id' => $id, 'code' => $body );
		}

		return $out;
	}

	/**
	 * Register the engine and hand it its configuration.
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( DBP_Kernel::is_fragment_request() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			DBP_Kernel::asset_url( 'pro/assets/dbp-nav.js' ),
			array(),
			DBP_Kernel::asset_version(),
			true
		);

		$settings   = DBP_Kernel::settings();
		$connection = DBP_Kernel::connection();

		$locked = array( '/panier', '/cart', '/commande', '/checkout', '/mon-compte', '/my-account', '/wp-admin', '/wp-login', '/wp-json', '/my-wallet', '/wallet', '/woo-wallet', '/mon-portefeuille', '/wc-api' );
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				$path = wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH );
				if ( is_string( $path ) && '/' !== $path ) { $locked[] = untrailingslashit( $path ); }
			}
		}

		$permalinks = get_option( 'woocommerce_permalinks', array() );
		$product_paths = array( '/product/', '/produit/' );
		if ( is_array( $permalinks ) && ! empty( $permalinks['product_base'] ) ) {
			$base = '/' . trim( (string) $permalinks['product_base'], '/' ) . '/';
			// Dynamic category tokens cannot be matched as literal path segments.
			if ( '/' !== $base && false === strpos( $base, '%' ) ) { $product_paths[] = $base; }
		}
		$config = array(
			'route'       => DBP_Kernel::route(),
			'nativeProducts' => true,
			'productWarm' => array_map( static function ( $path ) { return DBP_Kernel::asset_url( $path ); }, array(
				'assets/css/native-product.css', 'assets/css/native-product-pro15.css',
				'assets/js/native-product.js', 'assets/css/express-checkout.css', 'assets/js/express-checkout.js',
			) ),
			/*
			 * pro.30: whether a product document may be warmed with
			 * <link rel="prefetch"> before the shopper opens it.
			 *
			 * This used to stand down for any guest carrying a WooCommerce cart
			 * or session cookie, because warming then fetched a personalised,
			 * uncacheable document -- bandwidth spent on a copy the shopper
			 * would not be served. That is no longer what those cookies mean:
			 * Security::public_cache_allowed() now lets a guest with a cart be
			 * served from the shared cache, and cart state is kept out of the
			 * document so the copy is correct for everyone. Warming is both
			 * cheap and useful for exactly the shopper this used to exclude --
			 * the one already browsing products.
			 *
			 * Deferring to the cache gate also keeps the two in step: a request
			 * the gate will not cache is still never warmed.
			 */
			'publicProductWarm' => class_exists( 'Delicat_Builder_V9_Security', false )
				&& is_callable( array( 'Delicat_Builder_V9_Security', 'public_cache_allowed' ) )
				? Delicat_Builder_V9_Security::public_cache_allowed()
				: ( ! is_user_logged_in() && empty( $_COOKIE['woocommerce_items_in_cart'] ) && empty( $_COOKIE['wp_woocommerce_session_' . COOKIEHASH] ) ),
			'productPaths' => array_values( array_unique( $product_paths ) ),
			'generation'  => DBP_Kernel::generation(),
			'home'        => home_url( '/' ),
			'locked'      => $locked,
			'blockParams' => array( 'add-to-cart', 'remove_item', 'undo_item', 'wc-ajax', 'download_file', 'logout', 'customer-logout', '_wpnonce', 'nonce', 'action', 'dip_action', 'key', 'token', 'code', 'payment_method', 'preview', 'delicat_builder_preview' ),
			'cacheTtl'    => (int) $settings['nav_cache_ttl'] * 1000,
			'cacheMax'    => (int) $settings['nav_cache_max'],
			/* Fragments are rendered uncached and never stored, so a speculative
			 * fetch is a full server render for a page nobody may open — on a
			 * touch device every scroll that begins on a link would pay for one.
			 * Prefetch stays off until fragments are genuinely cacheable. */
			'fragmentPrefetch' => false,
			'prefetch'    => ! empty( $settings['prefetch'] ) ? 1 : 0,
			'budget'      => (int) $settings['prefetch_budget'],
			'transitions' => ! empty( $settings['transitions'] ) ? 1 : 0,
			'skeletons'   => ! empty( $settings['skeletons'] ) ? 1 : 0,
			'lowData'     => ( ! empty( $settings['low_data_mode'] ) && ! empty( $connection['slow'] ) ) ? 1 : 0,
			'diagnostics' => ! empty( $settings['diagnostics'] ) ? 1 : 0,
		);

		wp_add_inline_script(
			self::HANDLE,
			'window.DBPNavConfig=' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}
