<?php
namespace Delicat\V10;

defined( 'ABSPATH' ) || exit;

/**
 * What kind of request is this? Asked once, answered once, read everywhere.
 *
 * In V9 the same questions were re-asked in dozens of places with slightly
 * different answers — is_cart() here, a path regex there, a $_GET sniff
 * somewhere else — which is how a page ended up both prerendered and marked
 * uncacheable, and how the cache tier disagreed with the nonce lifetime.
 *
 * Two of these are resolved before WordPress has parsed the query, because the
 * kernel must decide what to load at plugins_loaded: `kind` (the shape of the
 * request) and `privacy` (whether a response may be shared between people).
 * Everything route-specific waits for `wp`, by which time is_product() and
 * friends are safe to call.
 */
final class Context {

	/* Request shapes. A request is exactly one of these. */
	public const KIND_FRONT  = 'front';
	public const KIND_ADMIN  = 'admin';
	public const KIND_AJAX   = 'ajax';
	public const KIND_REST   = 'rest';
	public const KIND_CRON   = 'cron';
	public const KIND_CLI    = 'cli';
	public const KIND_LOGIN  = 'login';
	public const KIND_FEED   = 'feed';

	/* Storefront routes. Resolved at `wp`. */
	public const ROUTE_HOME     = 'home';
	public const ROUTE_PRODUCT  = 'product';
	public const ROUTE_CATALOG  = 'catalog';
	public const ROUTE_CART     = 'cart';
	public const ROUTE_CHECKOUT = 'checkout';
	public const ROUTE_ACCOUNT  = 'account';
	public const ROUTE_WALLET   = 'wallet';
	public const ROUTE_SEARCH   = 'search';
	public const ROUTE_PAGE     = 'page';
	public const ROUTE_OTHER    = 'other';

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private $kind;

	/** @var string|null resolved lazily at `wp` */
	private $route = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->kind = $this->detect_kind();
	}

	private function detect_kind(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return self::KIND_CLI;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return self::KIND_CRON;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return self::KIND_REST;
		}
		/*
		 * wp_doing_ajax() is not available this early on every install, and
		 * WooCommerce's own ?wc-ajax= transport never sets DOING_AJAX at all —
		 * it is a front-end request by WordPress's reckoning but must never be
		 * treated as a page. Both are AJAX here.
		 */
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shape detection only.
			return self::KIND_AJAX;
		}
		if ( is_admin() ) {
			return self::KIND_ADMIN;
		}

		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? (string) $_SERVER['SCRIPT_NAME'] : '';
		if ( '' !== $script && in_array( basename( $script ), array( 'wp-login.php', 'wp-signup.php' ), true ) ) {
			return self::KIND_LOGIN;
		}

		return self::KIND_FRONT;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function is( string $kind ): bool {
		return $this->kind === $kind;
	}

	/** A storefront page a visitor looks at — the only shape that gets chrome. */
	public function is_page_view(): bool {
		if ( self::KIND_FRONT !== $this->kind ) {
			return false;
		}
		/* Feeds, embeds, sitemaps and previews are front-end but are not the app. */
		if ( function_exists( 'is_feed' ) && ( is_feed() || is_embed() || is_trackback() ) ) {
			return false;
		}
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return false;
		}
		return true;
	}

	/**
	 * The storefront route. Calling this before the `wp` action returns
	 * ROUTE_OTHER rather than a wrong answer, because the query is not parsed
	 * yet and WooCommerce's conditionals would lie.
	 */
	public function route(): string {
		if ( null !== $this->route ) {
			return $this->route;
		}
		if ( ! did_action( 'wp' ) ) {
			return self::ROUTE_OTHER;
		}
		$this->route = $this->detect_route();
		return $this->route;
	}

	private function detect_route(): string {
		if ( function_exists( 'is_front_page' ) && is_front_page() ) {
			return self::ROUTE_HOME;
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			return self::ROUTE_SEARCH;
		}

		if ( function_exists( 'is_product' ) ) {
			if ( is_product() ) {
				return self::ROUTE_PRODUCT;
			}
			if ( is_cart() ) {
				return self::ROUTE_CART;
			}
			if ( is_checkout() ) {
				return self::ROUTE_CHECKOUT;
			}
			if ( is_shop() || is_product_taxonomy() ) {
				return self::ROUTE_CATALOG;
			}
			if ( is_account_page() ) {
				return $this->is_wallet_endpoint() ? self::ROUTE_WALLET : self::ROUTE_ACCOUNT;
			}
		}

		if ( $this->is_wallet_endpoint() ) {
			return self::ROUTE_WALLET;
		}
		if ( function_exists( 'is_page' ) && is_page() ) {
			return self::ROUTE_PAGE;
		}
		return self::ROUTE_OTHER;
	}

	private function is_wallet_endpoint(): bool {
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'woo-wallet' ) ) {
			return true;
		}
		if ( function_exists( 'is_page' ) && is_page( array( 'my-wallet', 'wallet', 'portefeuille', 'mon-portefeuille' ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * May this response be stored where another person could receive it?
	 *
	 * Deliberately pessimistic and resolvable with no WordPress query: it runs
	 * before `wp` so the kernel and the cache headers agree from the first byte.
	 * Getting this wrong in the permissive direction serves one customer's cart
	 * or wallet balance to another, so every uncertain case answers "private".
	 */
	public function is_private(): bool {
		static $private = null;
		if ( null !== $private ) {
			return $private;
		}

		$private = true;

		if ( self::KIND_FRONT !== $this->kind && self::KIND_FEED !== $this->kind ) {
			return $private;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return $private;
		}

		$path = strtolower( (string) wp_parse_url( $this->request_uri(), PHP_URL_PATH ) );
		foreach ( array( 'cart', 'panier', 'checkout', 'commande', 'my-account', 'mon-compte', 'wallet', 'portefeuille', 'order-received', 'commande-recue' ) as $needle ) {
			if ( false !== strpos( $path, '/' . $needle ) ) {
				return $private;
			}
		}

		foreach ( array_keys( $_COOKIE ) as $name ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- shape detection only.
			$name = (string) $name;
			if ( preg_match( '/^(?:wordpress_logged_in|woocommerce_items_in_cart|wp_woocommerce_session|delicat_|dip_)/i', $name ) ) {
				return $private;
			}
		}

		$private = false;
		return $private;
	}

	public function request_uri(): string {
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	}

	/** Reset between requests in tests. Never called in production. */
	public static function reset(): void {
		self::$instance = null;
	}
}
