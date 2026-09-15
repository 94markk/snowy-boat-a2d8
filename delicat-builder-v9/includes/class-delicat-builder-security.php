<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Security {
	/** Memo for the immutable half of public_cache_allowed(). */
	private static ?bool $public_cache_static = null;

	/* ------------------------------------------------------------------ */
	/* RC32 hardening: XML-RPC, author enumeration, login throttling        */
	/* ------------------------------------------------------------------ */

	private static function login_bucket( string $username ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return 'dlcv9_lf_' . substr( hash_hmac( 'sha256', $ip . '|' . strtolower( trim( $username ) ), (string) wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function login_failed( $username ): void {
		$key   = self::login_bucket( is_string( $username ) ? $username : '' );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 15 * MINUTE_IN_SECONDS );
	}

	public static function login_throttle( $user, $username = '' ) {
		if ( ! is_string( $username ) || '' === $username ) {
			return $user;
		}
		$count = (int) get_transient( self::login_bucket( $username ) );
		if ( $count >= 8 ) {
			return new WP_Error( 'delicat_builder_v9_login_throttled', __( 'Trop de tentatives. Réessayez dans quelques minutes.', 'delicat-builder-v9' ) );
		}
		return $user;
	}

	public static function login_error_generic( $message ) {
		return __( 'Identifiants incorrects.', 'delicat-builder-v9' );
	}

	public static function block_author_enumeration(): void {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		if ( ( is_author() && ! is_user_logged_in() ) || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection.
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	public static function boot(): void {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ) );
		add_filter( 'authenticate', array( __CLASS__, 'login_throttle' ), PHP_INT_MAX, 2 );
		add_filter( 'login_errors', array( __CLASS__, 'login_error_generic' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_author_enumeration' ), 1 );
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'wp_headers', array( __CLASS__, 'safe_headers' ), 20 );
		add_action( 'send_headers', array( __CLASS__, 'remove_disclosure_headers' ), 1000 );
		add_action( 'template_redirect', array( __CLASS__, 'protect_private_response' ), 0 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'secure_rest_response' ), 20, 3 );
	}

	public static function remove_disclosure_headers(): void {
		if ( headers_sent() || ! function_exists( 'header_remove' ) || ! Delicat_Builder_V9_Core::is_enabled() ) {
			return;
		}
		$settings = Delicat_Builder_V9_Core::settings();
		if ( ! empty( $settings['safe_headers'] ) ) {
			header_remove( 'X-Powered-By' );
		}
	}

	public static function safe_headers( $headers ): array {
		/* RC18: a foreign wp_headers filter may hand over a non-array; a TypeError
		 * here lives in a bootstrap-critical file and would deactivate the plugin. */
		$headers = is_array( $headers ) ? $headers : array();
		if ( ! Delicat_Builder_V9_Core::is_enabled() ) {
			return $headers;
		}

		$settings = Delicat_Builder_V9_Core::settings();
		if ( empty( $settings['safe_headers'] ) ) {
			return $headers;
		}

		// Conservative headers only: avoid CSP/COEP/CORP defaults that can break payment gateways/CDNs.
		if ( ! isset( $headers['X-Content-Type-Options'] ) ) {
			$headers['X-Content-Type-Options'] = 'nosniff';
		}
		if ( ! isset( $headers['Referrer-Policy'] ) ) {
			$headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
		}
		/* RC32 */
		if ( ! isset( $headers['Permissions-Policy'] ) ) {
			$headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=(), interest-cohort=()';
		}
		/* self::request_is_secure(), not is_ssl(): behind Cloudflare/LiteSpeed the
		 * latter is false on an https site, and HSTS - the one header that keeps a
		 * browser off plain http - would silently never be sent. */
		if ( self::request_is_secure() && ! isset( $headers['Strict-Transport-Security'] ) ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}
		// SAMEORIGIN blocks clickjacking without restricting payment/CDN resources
		// loaded by the page itself. Existing server policy always wins.
		if ( ! isset( $headers['X-Frame-Options'] ) ) {
			$headers['X-Frame-Options'] = 'SAMEORIGIN';
		}
		if ( ! isset( $headers['X-Permitted-Cross-Domain-Policies'] ) ) {
			$headers['X-Permitted-Cross-Domain-Policies'] = 'none';
		}

		return $headers;
	}

	/**
	 * Is the customer's connection encrypted, whatever PHP was reached over?
	 *
	 * Thin accessor for the bootstrap helper so every caller in this file, and
	 * the Pro header layer, asks one question one way. Falls back to is_ssl()
	 * if the helper is ever missing: this file runs on every request and a
	 * fatal here would take the storefront with it.
	 */
	public static function request_is_secure(): bool {
		return function_exists( 'delicat_builder_v9_request_is_secure' ) ? delicat_builder_v9_request_is_secure() : is_ssl();
	}

	/** Shared route guard for the Pro fragment endpoint. */
	public static function navigation_request_allowed(): bool {
		return ! self::request_has_sensitive_action() && ! self::is_private_context();
	}

	private static function request_has_sensitive_action(): bool {
		// Delicat Identity uses the site root with ?dip_action=login|callback.
		// Treat those requests as private even though the path itself is '/'.
		$dip_action = isset( $_GET['dip_action'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( $_GET['dip_action'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		if ( in_array( $dip_action, array( 'login', 'callback' ), true ) ) {
			return true;
		}

		$keys = array(
			'download_file',
			'token',
			'code',
			'logout',
			'_wpnonce',
			'nonce',
			'action',
			'wc-ajax',
			'add-to-cart',
			'remove_item',
			'undo_item',
			'customer-logout',
			'key',
			'order-pay',
		);

		foreach ( $keys as $key ) {
			if ( isset( $_GET[ $key ] ) || isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		return false;
	}

	/**
	 * A shared-cache response must never contain cart, account, currency or
	 * comment-author state. Inspect cookie names only; values are never logged.
	 */
	/**
	 * Cookies that genuinely personalise a rendered document.
	 *
	 * WooCommerce cart/session cookies are deliberately NOT treated as private
	 * here. Until pro.30 they were, and that single line kept the whole
	 * storefront out of the page cache: WooCommerce sets a cookie the moment a
	 * shopper touches a product, so from then on every homepage, archive and
	 * product view was a full dynamic render for the rest of their session.
	 *
	 * A guest cart can no longer reach a shared document. Every renderer that
	 * prints cart state asks shared_document() first and emits the neutral
	 * empty-cart markup when the response is headed for the public cache, and
	 * the client restores the real count from `woocommerce_items_in_cart`
	 * before paint (see session.js and the bottom-nav corrector). What remains
	 * below is the set of cookies that change the document for a reason the
	 * client cannot repair: an identity, a password gate, or a currency.
	 */
	public static function has_private_cookie(): bool {
		foreach ( array_keys( $_COOKIE ) as $cookie_name ) {
			$name = strtolower( sanitize_key( (string) $cookie_name ) );

			if ( 'dmc_currency' === $name ) {
				if ( self::currency_cookie_is_default( (string) wp_unslash( $_COOKIE[ $cookie_name ] ) ) ) {
					continue;
				}
				return true;
			}

			/* An authenticated, password-gated or comment-author identity is
			 * rendered into the document and must never be shared. */
			if (
				0 === strpos( $name, 'wordpress_logged_in' )
				|| 0 === strpos( $name, 'wordpress_sec' )
				|| 0 === strpos( $name, 'wp_postpass' )
				|| 0 === strpos( $name, 'comment_author' )
			) {
				return true;
			}

			/* Third-party currency switchers render different prices. Matched
			 * exactly rather than by substring: the old `strpos($name,'currency')`
			 * also caught unrelated analytics and consent cookies. */
			if (
				'aelia_cs_selected_currency' === $name
				|| 'aelia_cs_selected_country' === $name
				|| 'wcml_currency' === $name
				|| 'wmc_current_currency' === $name
			) {
				return true;
			}
		}

		return (bool) apply_filters( 'delicat_builder_v9_has_private_cookie', false );
	}

	/**
	 * True when a `dmc_currency` cookie carries no personalisation, i.e. it
	 * holds the store's effective default currency or the currency is locked.
	 * Extracted from has_private_cookie() unchanged.
	 */
	private static function currency_cookie_is_default( string $raw ): bool {
		$value      = strtoupper( sanitize_key( $raw ) );
		$base       = strtoupper( sanitize_key( (string) get_option( 'woocommerce_currency', 'HTG' ) ) );
		$dmc        = get_option( 'dmc_settings', array() );
		$default    = is_array( $dmc ) ? strtoupper( sanitize_key( (string) ( $dmc['default_currency'] ?? '' ) ) ) : '';
		$locked     = is_array( $dmc ) && 'yes' === ( $dmc['lock_currency'] ?? 'no' );
		$currencies = get_option( 'dmc_currencies', array() );

		if ( '' !== $default && ( ! is_array( $currencies ) || empty( $currencies[ $default ] ) || 'yes' !== ( $currencies[ $default ]['enabled'] ?? 'no' ) ) ) {
			$default = '';
		}
		$default = '' !== $default ? $default : $base;

		// A default-currency cookie carries no personalization. RC34's
		// currency runtime expires legacy copies during normal rendering.
		return $locked || ( '' !== $value && hash_equals( $default, $value ) );
	}

	/** Conservative cache gate shared by native catalog renderers. */
	/**
	 * RC32: signed-in customers can be served from LiteSpeed's *private* cache
	 * (one copy per user, never shared) on public surfaces — home, shop,
	 * categories, products, information pages. Everything user-mutating or
	 * account-related (cart, checkout, account, wallet, sensitive actions,
	 * previews) and every user who can see the admin bar stays uncached.
	 * The client-side layers (cart fragments, wallet refresh, notifications
	 * snapshot, cookie badge sync) keep a cached copy fresh.
	 */
	public static function private_cache_allowed(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() ) {
			return false;
		}
		if ( ! (bool) apply_filters( 'delicat_builder_v9_private_cache', true ) ) {
			return false;
		}
		if ( current_user_can( 'edit_posts' ) || is_admin_bar_showing() ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( ! empty( $_GET ) || self::request_has_sensitive_action() || self::is_private_context() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cache bypass only.
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
			return false;
		}
		$path = strtolower( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array( '/my-wallet', '/woo-wallet', '/wallet', '/my-account', '/checkout', '/cart', '/order' ) as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return false;
			}
		}
		return true;
	}

	/** Tell LiteSpeed the current response may be cached privately (per user). */
	public static function hint_private_cache( string $reason ): void {
		do_action( 'litespeed_control_set_private', $reason );
		if ( ! headers_sent() ) {
			header( 'X-Delicat-V9-Cache: private' );
		}
	}

	/**
	 * Whether this response may be served from the shared (public) page cache.
	 *
	 * Asked by the homepage, product, archive, native-page and asset layers, so
	 * it is the single switch that decides whether this storefront answers from
	 * LiteSpeed or rebuilds every document in PHP.
	 */
	public static function public_cache_allowed(): bool {
		/* Volatile conditions are re-read on every call. DONOTCACHEPAGE may be
		 * defined at any point in a request (maintenance mode, the identity
		 * bridge, a product fragment), and a memoised "yes" from before that
		 * point would put a private document into a shared cache. */
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in() ) {
			return false;
		}
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}

		/* The remainder cannot change within one request and costs a full
		 * $_COOKIE scan plus three get_option() reads. Five modules ask this
		 * question on every storefront request, so resolve it once. */
		if ( null === self::$public_cache_static ) {
			self::$public_cache_static = self::resolve_public_cache_static();
		}

		return self::$public_cache_static;
	}

	/** The per-request, immutable half of public_cache_allowed(). */
	private static function resolve_public_cache_static(): bool {
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return false;
		}
		if ( self::request_has_sensitive_action() || ! self::query_is_cache_safe() ) {
			return false;
		}
		if ( self::has_private_cookie() ) {
			return false;
		}
		if (
			class_exists( 'Delicat_Builder_V9_Multi_Currency', false )
			&& is_callable( array( 'Delicat_Builder_V9_Multi_Currency', 'instance' ) )
		) {
			$currency = Delicat_Builder_V9_Multi_Currency::instance();
			if ( is_callable( array( $currency, 'public_cache_variant_required' ) ) && $currency->public_cache_variant_required() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A query string does not by itself make a document private. The cache key
	 * already includes it, so `?orderby=price` is simply a different cacheable
	 * page.
	 *
	 * Until pro.30 this gate was `! empty( $_GET )` — any parameter at all
	 * bypassed the cache. That meant every ad-tagged landing (`utm_*`,
	 * `fbclid`, `gclid`, `msclkid`) and every archive page-2 or sort change was
	 * rebuilt in PHP, which on an ad-driven store is most of the traffic and
	 * exactly the navigation that felt slow.
	 *
	 * Only recognised campaign and catalog-navigation keys are allowed through.
	 * An unrecognised parameter still bypasses the cache rather than minting
	 * cache entries for arbitrary keys.
	 */
	private static function query_is_cache_safe(): bool {
		if ( empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cache gate only.
			return true;
		}

		$safe = array(
			/* Campaign and click identifiers. Content-neutral: they change the
			 * URL, never the document. */
			'gclid', 'gbraid', 'wbraid', 'gad_source', 'gclsrc', 'srsltid',
			'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id', 'igshid',
			'mc_cid', 'mc_eid', 'ref', 'referrer', '_gl', 'yclid', 'epik',
			/* Catalog navigation. Each is a different, still-shareable page. */
			'paged', 'page', 'orderby', 'order', 'product_orderby', 'product_count',
			'columns', 'per_page', 'min_price', 'max_price', 'rating_filter',
			'stock_status', 'on_sale', 'product_cat', 'product_tag', 'product_brand',
			'brand', 'post_type',
		);

		/* A site with its own content-neutral parameters can add them; the
		 * filter cannot be used to widen the gate to a private key because
		 * request_has_sensitive_action() is checked independently. */
		$safe = array_map( 'strtolower', (array) apply_filters( 'delicat_builder_v9_cache_safe_query_keys', $safe ) );

		foreach ( array_keys( (array) $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cache gate only.
			$name = strtolower( (string) $key );
			if ( in_array( $name, $safe, true ) ) {
				continue;
			}
			/* Prefix families: Analytics campaign keys and WooCommerce's
			 * layered-nav filters (`filter_size`, `query_type_size`, ...). */
			if (
				0 === strpos( $name, 'utm_' )
				|| 0 === strpos( $name, 'filter_' )
				|| 0 === strpos( $name, 'query_type_' )
			) {
				continue;
			}
			return false;
		}

		return true;
	}

	/**
	 * True when this response is a candidate for the shared page cache, and so
	 * must not contain anything specific to the current visitor.
	 *
	 * Renderers that would print the visitor's cart into the document ask this
	 * first and emit neutral empty-cart markup instead. `session.js` and the
	 * bottom-nav cookie corrector restore the real count on the client, so one
	 * cached copy stays correct for every shopper.
	 */
	public static function shared_document(): bool {
		return self::public_cache_allowed();
	}

	/**
	 * Privacy-minimal fixed-window limiter for public mutation endpoints.
	 * The transient key contains only an HMAC; raw IP/session data is not stored.
	 */
	public static function rate_limit_allowed( string $bucket, int $limit = 30, int $window = 60 ): bool {
		$bucket = sanitize_key( $bucket );
		$limit  = max( 1, min( 600, $limit ) );
		$window = max( 10, min( 3600, $window ) );
		$user_id = get_current_user_id();
		$actor = $user_id > 0 ? 'u:' . $user_id : '';

		// Guest cookies are attacker-controlled; never use them as rate-limit identity.
		if ( '' === $actor ) {
			$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
			$actor = 'ip:' . $remote;
		}

		$key = 'dbv9rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $actor, wp_salt( 'nonce' ) ), 0, 32 );
		$now = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['started'] ) || $now - absint( $state['started'] ) >= $window ) {
			set_transient( $key, array( 'started' => $now, 'count' => 1 ), $window );
			return true;
		}
		$count = absint( $state['count'] ?? 0 );
		if ( $count >= $limit ) {
			return false;
		}
		$state['count'] = $count + 1;
		set_transient( $key, $state, max( 1, $window - ( $now - absint( $state['started'] ) ) ) );
		return true;
	}

	private static function is_private_context(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( self::request_has_sensitive_action() ) {
			return true;
		}

		if (
			( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
		) {
			return true;
		}

		if (
			class_exists( 'Delicat_Builder_V9_Purchase_UI' )
			&& (
				Delicat_Builder_V9_Purchase_UI::cart_active()
				|| Delicat_Builder_V9_Purchase_UI::checkout_active()
			)
		) {
			return true;
		}

		if (
			! empty( $_GET['delicat_builder_preview'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ! empty( $_GET['dbv9t'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return true;
		}

		$path = strtolower( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) );
		foreach ( array( '/my-account', '/mon-compte', '/my-wallet', '/wallet', '/woo-wallet', '/mon-portefeuille', '/checkout', '/commande', '/cart', '/panier' ) as $private_path ) {
			if ( '' !== $path && 0 === strpos( $path, $private_path ) ) {
				return true;
			}
		}

		return false;
	}

	/** Wallet and order routes are personal even though they are ordinary pages. */
	private static function is_wallet_or_order_path(): bool {
		$path = strtolower( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array( '/my-wallet', '/woo-wallet', '/wallet', '/mon-portefeuille', '/order' ) as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a signed-in shopper's catalogue pages may be stored in LiteSpeed's
	 * per-user private cache. Off by default: it needs LiteSpeed's private cache to
	 * be configured, and it should be validated on staging before it is switched on.
	 */
	public static function private_page_cache_enabled(): bool {
		return (bool) apply_filters(
			'delicat_builder_v9_private_page_cache',
			(bool) get_option( 'delicat_builder_v9_private_page_cache', false )
		);
	}

	/**
	 * Two tiers, not one.
	 *
	 * Until pro.16 this sent `no-store` to every signed-in visitor and every guest
	 * carrying a WooCommerce cookie — that is, to nearly everyone, on every page.
	 * `no-store` on a main-frame document disqualifies it from the browser's
	 * back/forward cache, so pressing Back from a product page, the single most
	 * common movement on a storefront, meant a full network load and a full uncached
	 * WordPress render instead of an instant restore. It also defined DONOTCACHEPAGE
	 * at priority 0, which made private_cache_allowed() — and the whole RC32
	 * private-cache design documented in its own docblock — permanently unreachable,
	 * and stopped TurboNav printing speculation rules for signed-in customers.
	 *
	 * Tier A, unchanged: anything genuinely sensitive — cart, checkout, account,
	 * wallet, order, a sensitive action, a preview, a navigation fragment — stays
	 * no-store, uncacheable, everywhere.
	 *
	 * Tier B, new: a catalogue document (home, shop, category, product, information
	 * page) belonging to someone with state gets `private, no-cache, must-revalidate`
	 * and `Vary: Cookie`. `private` keeps it out of every shared cache; `no-cache`
	 * forces revalidation before the browser may reuse it, so a stale wallet figure
	 * can never be painted from the HTTP cache. What it does allow is the
	 * back/forward cache, which is a same-tab, same-user, in-process restore of a
	 * document that person already had — not a cache anyone else can read — and the
	 * state layers already repaint on restore (pro/assets/dbp-state.js and
	 * assets/js/session.js both refresh on a persisted pageshow).
	 *
	 * Server-side private caching stays off unless the merchant switches it on, so
	 * this change by itself alters no cache storage at all.
	 */
	/**
	 * Which of the three cache tiers this response belongs to.
	 *
	 * 'shared'    — nothing personal about it; this function says nothing about it.
	 * 'personal'  — a catalogue document belonging to someone with state.
	 * 'sensitive' — commerce, account, wallet, nonce, preview or navigation fragment.
	 *
	 * Separated from the header emission so the decision can be tested directly.
	 */
	public static function response_cache_tier(): string {
		try {
			if (
				self::is_private_context()
				|| isset( $_GET['dbp_nav'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- cache policy only.
				|| isset( $_SERVER['HTTP_X_DELICAT_PRO_NAV'] )
				|| self::is_wallet_or_order_path()
			) {
				return 'sensitive';
			}
			return ( is_user_logged_in() || self::has_private_cookie() ) ? 'personal' : 'shared';
		} catch ( Throwable $error ) {
			/* RC18: context probes call into optional modules; never let one fatal
			 * inside this critical file. Fail closed: treat the response as private. */
			unset( $error );
			return 'sensitive';
		}
	}

	public static function protect_private_response(): void {
		$tier = self::response_cache_tier();
		if ( 'shared' === $tier ) {
			return;
		}

		if ( 'sensitive' === $tier ) {
			// Do not let a page cache/CDN store commerce, nonce, account, wallet or
			// authenticated action responses as public catalog HTML.
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'Pragma: no-cache', true );
			header( 'X-Robots-Tag: noindex, noarchive', false );
			do_action( 'litespeed_control_set_nocache', 'Delicat Builder private/sensitive response' );
			return;
		}

		/* Tier B. Deliberately no nocache_headers(): its 1984 Expires and Pragma are
		 * what make a response look unstorable to every layer, bfcache included. */
		header( 'Cache-Control: private, no-cache, must-revalidate', true );
		header( 'Vary: Cookie', false );
		header( 'X-Robots-Tag: noindex, noarchive', false );

		if ( self::private_page_cache_enabled() ) {
			self::hint_private_cache( 'Delicat catalogue document (per-user)' );
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Delicat catalogue document (browser-private only)' );
	}

	public static function secure_rest_response( $response, $server, $request ) {
		if ( ! ( $response instanceof WP_HTTP_Response ) || ! is_object( $request ) || ! is_callable( array( $request, 'get_route' ) ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		$is_builder_route = 0 === strpos( $route, '/delicat-builder-v9/' );
		$is_compat_route  = 0 === strpos( $route, '/delicat/v1/notifications' );
		if ( ! $is_builder_route && ! $is_compat_route ) {
			return $response;
		}

		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Referrer-Policy', 'strict-origin-when-cross-origin' );
		$response->header( 'X-Frame-Options', 'SAMEORIGIN' );
		$response->header( 'X-Permitted-Cross-Domain-Policies', 'none' );

		$method = strtoupper( (string) $request->get_method() );
		$private_route = false;
		foreach ( array( '/read', '/dismiss', '/state', '/click', '/push/subscription', '/notifications/device' ) as $fragment ) {
			if ( false !== strpos( $route, $fragment ) ) { $private_route = true; break; }
		}
		if ( 'GET' !== $method || $private_route ) {
			$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Vary', 'Cookie', false );
		}
		return $response;
	}

	public static function sanitize_selector( string $selector ): string {
		$selector = trim( wp_strip_all_tags( $selector ) );

		// Beta 1 intentionally accepts only simple selectors to keep DOM replacement predictable.
		if ( ! preg_match( '/^(main|#[A-Za-z][A-Za-z0-9_-]*|\.[A-Za-z][A-Za-z0-9_-]*)$/', $selector ) ) {
			return 'main';
		}

		return $selector;
	}
}


/* ------------------------------------------------------------------ */
/* RC51.63 hardening — self-registering, presentation-safe additions. */
/* WooCommerce, wallet and payment flows are untouched engines; these */
/* close generic WordPress attack surface and add missing headers.    */
/* ------------------------------------------------------------------ */

/* XML-RPC serves no purpose on this storefront (MonCash/NatCash IPN and the
 * apps use REST/admin-ajax); it is a standing brute-force and pingback
 * amplification surface. */
add_filter( 'xmlrpc_enabled', '__return_false' );

/* Do not advertise the WordPress version to scanners. */
add_action( 'init', static function (): void {
	remove_action( 'wp_head', 'wp_generator' );
}, 9 );
add_filter( 'the_generator', '__return_empty_string' );

/* Block anonymous user enumeration through the REST users collection. */
add_filter( 'rest_endpoints', static function ( $endpoints ) {
	if ( is_array( $endpoints ) && ! current_user_can( 'list_users' ) ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\\d]+)'] );
	}
	return $endpoints;
} );

/* Complete the frontend header set safe_headers() already ships. HSTS is
 * bare max-age (no includeSubDomains: other Delicat services may live on
 * subdomains); Permissions-Policy leaves payment untouched on purpose. */
add_filter( 'wp_headers', static function ( $headers ) {
	if ( is_admin() || ! is_array( $headers ) ) {
		return $headers;
	}
	if ( ! isset( $headers['Permissions-Policy'] ) ) {
		$headers['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=()';
	}
	if ( ! isset( $headers['Referrer-Policy'] ) ) {
		$headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
	}
	if ( Delicat_Builder_V9_Security::request_is_secure() && ! isset( $headers['Strict-Transport-Security'] ) ) {
		$headers['Strict-Transport-Security'] = 'max-age=15552000';
	}
	return $headers;
}, 20 );


/* ------------------------------------------------------------------ */
/* RC88 — sensitive fulfillment field vault.                           */
/* ------------------------------------------------------------------ */
if ( ! function_exists( 'delicat_builder_v9_sensitive_crypto_available' ) ) {
	function delicat_builder_v9_sensitive_crypto_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) || ( function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) );
	}
}

add_action( 'admin_notices', static function () {
	if ( ! current_user_can( 'manage_woocommerce' ) || delicat_builder_v9_sensitive_crypto_available() ) { return; }
	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Delicat Builder : chiffrement des champs sensibles indisponible.', 'delicat-builder-v9' ) . '</strong> ' . esc_html__( 'Activez Sodium ou OpenSSL sur PHP avant d’utiliser un champ produit de type mot de passe.', 'delicat-builder-v9' ) . '</p></div>';
} );

if ( ! function_exists( 'delicat_builder_v9_sensitive_key' ) ) {
	function delicat_builder_v9_sensitive_key(): string {
		return hash( 'sha256', (string) wp_salt( 'auth' ) . '|delicat-builder-v9-sensitive-v1', true );
	}
}

if ( ! function_exists( 'delicat_builder_v9_seal_sensitive_value' ) ) {
	function delicat_builder_v9_seal_sensitive_value( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) { return ''; }
		$key = delicat_builder_v9_sensitive_key();
		try {
			if ( function_exists( 'sodium_crypto_secretbox' ) ) {
				$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$cipher = sodium_crypto_secretbox( $value, $nonce, $key );
				return 's1:' . base64_encode( $nonce . $cipher );
			}
			if ( function_exists( 'openssl_encrypt' ) ) {
				$iv = random_bytes( 12 );
				$tag = '';
				$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				if ( false !== $cipher ) { return 'o1:' . base64_encode( $iv . $tag . $cipher ); }
			}
		} catch ( Throwable $error ) {
			return '';
		}
		return '';
	}
}

if ( ! function_exists( 'delicat_builder_v9_open_sensitive_value' ) ) {
	function delicat_builder_v9_open_sensitive_value( $sealed ): string {
		$sealed = is_scalar( $sealed ) ? (string) $sealed : '';
		if ( strlen( $sealed ) < 4 ) { return ''; }
		$key = delicat_builder_v9_sensitive_key();
		try {
			if ( 0 === strpos( $sealed, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
				$raw = base64_decode( substr( $sealed, 3 ), true );
				if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) { return ''; }
				$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
				$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
				return false === $plain ? '' : (string) $plain;
			}
			if ( 0 === strpos( $sealed, 'o1:' ) && function_exists( 'openssl_decrypt' ) ) {
				$raw = base64_decode( substr( $sealed, 3 ), true );
				if ( false === $raw || strlen( $raw ) <= 28 ) { return ''; }
				$iv = substr( $raw, 0, 12 );
				$tag = substr( $raw, 12, 16 );
				$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
				return false === $plain ? '' : (string) $plain;
			}
		} catch ( Throwable $error ) {
			return '';
		}
		return '';
	}
}

/* Admin-only display of decrypted secure fulfillment fields. Nothing is added
 * to customer emails, REST output, cart fragments or ordinary order metadata. */
add_action( 'woocommerce_after_order_itemmeta', static function ( $item_id, $item ) {
	if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) || ! $item instanceof WC_Order_Item ) { return; }
	$raw = $item->get_meta( '_delicat_secure_fields', true );
	if ( ! is_string( $raw ) || '' === $raw ) { return; }
	$fields = json_decode( $raw, true );
	if ( ! is_array( $fields ) || ! $fields ) { return; }
	echo '<div class="delicat-secure-order-fields" style="margin:8px 0;padding:9px 11px;border-left:3px solid #6846ff;background:#f7f5ff"><strong>' . esc_html__( 'Informations sécurisées de livraison', 'delicat-builder-v9' ) . '</strong>';
	foreach ( $fields as $field ) {
		if ( ! is_array( $field ) ) { continue; }
		$label = sanitize_text_field( (string) ( $field['label'] ?? '' ) );
		$value = delicat_builder_v9_open_sensitive_value( $field['value'] ?? '' );
		if ( '' === $label || '' === $value ) { continue; }
		echo '<div style="margin-top:4px"><span>' . esc_html( $label ) . ':</span> <code>' . esc_html( $value ) . '</code></div>';
	}
	echo '</div>';
}, 20, 2 );

/*
 * CART-AWARE CACHE VARIANT
 * ------------------------
 * Several modules decide what to ship by reading WooCommerce's cart cookie:
 * Performance::dequeue_managed_noncritical(), TurboNav::drop_guest_cart_fragments(),
 * Native_Product::slim_assets() and Storefront_Fix all drop `wc-cart-fragments`
 * for a logged-out visitor whose cart is empty, because for that visitor the
 * script synchronises nothing and still costs an admin-ajax POST per page view.
 *
 * That decision changes the emitted document, so once these pages are publicly
 * cached (see public_cache_allowed()) it has to be part of the cache key.
 * Otherwise a shopper with items can be served the copy built for an empty
 * cart, and the consequence is not cosmetic: session.js asks WooCommerce to
 * refresh the mini-cart by triggering `wc_fragment_refresh`, an event only
 * wc-cart-fragments listens for. Without that script on the page the trigger
 * goes nowhere and the cart panel keeps saying "Votre panier est vide" while
 * the badge, corrected from the cookie, shows items.
 *
 * LiteSpeed's WooCommerce integration generally varies on this cookie already,
 * but the storefront's correctness should not rest on another plugin's default
 * staying as it is. Declaring it here makes the variant explicit: two cached
 * copies per URL -- empty cart and non-empty -- instead of one wrong one.
 *
 * The cart count and the cart panel itself stay out of the document regardless;
 * a count is unbounded and two shoppers with three items each still have
 * different baskets, so those are restored on the client. See shared_document().
 */
add_filter(
	'litespeed_vary_cookies',
	static function ( $cookies ) {
		$cookies = is_array( $cookies ) ? $cookies : array();
		$cookies[] = 'woocommerce_items_in_cart';
		return array_values( array_unique( $cookies ) );
	}
);
