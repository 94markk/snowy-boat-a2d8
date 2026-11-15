<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Bottom Nav (RC30) — the mobile navigation bar, rebuilt.
 *
 * Floating pill with five destinations (Accueil, Wallet, Boutique as the raised
 * centre action, Panier with its live count, Compte). 9.11.1: the 9.10.1 look is
 * back by the owner's choice (the 9.11.0 flat bar is gone). Phones and tablets only
 * (≤1023px since 9.14.0; from 1024px the same links sit in the header, see
 * header_links()), on the cart page too (9.3.3: shoppers kept losing the bar there);
 * never on checkout and the thank-you page, where the checkout dock owns the
 * bottom edge. Body carries `delicat-shell-mobile-nav-active` so the
 * product dock, the sheets and the chat launcher keep their existing offsets.
 */
final class Delicat_Builder_V9_Bottom_Nav {
	private static bool $booted   = false;
	private static bool $rendered = false;
	/** 9.14.0: links() computed once per request (the header nav and the bar both read it). */
	private static ?array $links  = null;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 50 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 9 );
	}

	public static function expected(): bool {
		if ( ! self::nav_page() ) {
			return false;
		}
		return (bool) apply_filters( 'delicat_builder_v9_bottom_nav', true );
	}

	/** 9.14.0: the pages that get a navigation bar at all (never checkout, order-pay, thank-you). */
	private static function nav_page(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return false;
		}
		return true;
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::expected() ) {
			$classes[] = 'delicat-shell-mobile-nav-active';
			$classes[] = 'delicat-bottom-nav-active';
		}
		if ( self::buy_page() ) {
			$classes[] = 'dlc-buy-page';
		}
		return array_values( array_unique( $classes ) );
	}

	/**
	 * 9.11.0: a purchase page (product, cart, checkout, order-pay). It keeps ONE bar
	 * at the bottom - the purchase bar - so the bottom menu and the chat bubbles
	 * step aside there (CSS keyed on this body class, which travels with every
	 * soft navigation). The menu markup stays in the document so the engine keeps
	 * it across navigations. The thank-you page is not one: help stays reachable.
	 */
	public static function buy_page(): bool {
		if ( is_admin() || wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			return false;
		}
		return ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() );
	}

	public static function assets(): void {
		if ( ! self::expected() ) {
			return;
		}
		wp_enqueue_style( 'delicat-builder-v9-bottom-nav', DELICAT_BUILDER_V9_URL . 'assets/css/bottom-nav.css', array(), DELICAT_BUILDER_V9_VERSION );
	}

	/** 9.7.0: bottom bar icons from the shared Material Symbols set (sized by bottom-nav.css). */
	private static function icon( string $name ): string {
		$material = array( 'home' => 'home', 'wallet' => 'account-balance-wallet', 'shop' => 'storefront', 'cart' => 'shopping-cart', 'account' => 'person', 'orders' => 'package-2' );
		return isset( $material[ $name ] ) ? Delicat_Builder_V9_Design_System::icon( $material[ $name ], 24 ) : '';
	}

	private static function urls(): array {
		$wallet = home_url( '/my-wallet/' );
		if ( class_exists( 'Delicat_Builder_V9_Purchase_Native', false ) && is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'wallet_url' ) ) ) {
			$wallet = preg_replace( '/#.*$/', '', (string) Delicat_Builder_V9_Purchase_Native::wallet_url() );
		} else {
			$page = get_page_by_path( 'my-wallet', OBJECT, 'page' );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				$wallet = (string) get_permalink( $page );
			} elseif ( function_exists( 'wc_get_account_endpoint_url' ) ) {
				$wallet = (string) wc_get_account_endpoint_url( 'woo-wallet' );
			}
		}
		return array(
			'home'    => home_url( '/' ),
			'wallet'  => $wallet,
			'shop'    => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ),
			'cart'    => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/cart/' ),
			'account' => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' ),
		);
	}

	private static function active(): string {
		if ( is_front_page() ) {
			return 'home';
		}
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false !== strpos( $path, '/my-wallet' ) || false !== strpos( $path, '/woo-wallet' ) || false !== strpos( $path, '/wallet' ) ) {
			return 'wallet';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return 'account';
		}
		if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() ) || ( function_exists( 'is_product' ) && is_product() ) || is_search() ) {
			return 'shop';
		}
		return '';
	}

	/**
	 * 9.14.0: the bar's destinations as data, in the bar's order - ONE source for the
	 * bottom bar (phones and tablets) and the laptop header nav (Header Studio
	 * render_row()). Each entry: label, url, active (this page), guest (the Compte slot
	 * of a signed-out visitor, which opens the Delicat Identity sign-in dialog).
	 */
	public static function links(): array {
		if ( null !== self::$links ) {
			return self::$links;
		}
		$urls   = self::urls();
		$active = self::active();
		$items  = array(
			'home'    => __( 'Accueil', 'delicat-builder-v9' ),
			'wallet'  => __( 'Wallet', 'delicat-builder-v9' ),
			'shop'    => __( 'Boutique', 'delicat-builder-v9' ),
			'cart'    => __( 'Panier', 'delicat-builder-v9' ),
			'account' => __( 'Compte', 'delicat-builder-v9' ),
		);
		/* 9.3.7: seen from the Google Play build while credit gifts are hidden, the « Wallet »
		 * slot opens the customer's orders instead: the bar keeps five places and its raised
		 * centre, and the website's credit pages (which redirect to the shop) get no link. */
		if ( class_exists( 'Delicat_Builder_V9_App_View', false ) && Delicat_Builder_V9_App_View::hides_gifts() ) {
			$swapped = array();
			foreach ( $items as $key => $label ) {
				if ( 'wallet' === $key ) {
					$swapped['orders'] = __( 'Commandes', 'delicat-builder-v9' );
					continue;
				}
				$swapped[ $key ] = $label;
			}
			$items          = $swapped;
			$urls['orders'] = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'orders' ) : home_url( '/my-account/orders/' );
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'orders' ) ) {
				$active = 'orders';
			} elseif ( 'wallet' === $active ) {
				$active = '';
			}
		}
		$links = array();
		foreach ( $items as $key => $label ) {
			$guest         = 'account' === $key && ! is_user_logged_in() && class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && Delicat_Builder_V9_Identity_Bridge::frontend_login_available();
			$links[ $key ] = array(
				'label'  => $label,
				'url'    => $guest ? Delicat_Builder_V9_Identity_Bridge::guest_login_url() : $urls[ $key ],
				'active' => ( $key === $active ),
				'guest'  => $guest,
			);
		}
		self::$links = $links;
		return $links;
	}

	/**
	 * 9.14.0: the laptop header nav (shown from 1024 px, where the bottom bar hides):
	 * the bar's links minus Panier (the header has its cart button), in the order
	 * Accueil, Boutique, Wallet (« Commandes » on the Play build), Compte. Empty where
	 * no bar belongs (checkout, order-pay, thank-you) so the funnel gets no exits.
	 */
	public static function header_links(): array {
		if ( ! self::nav_page() || ! apply_filters( 'delicat_builder_v9_header_nav', true ) ) {
			return array();
		}
		$links = self::links();
		$out   = array();
		foreach ( array( 'home', 'shop', 'wallet', 'orders', 'account' ) as $key ) {
			if ( isset( $links[ $key ] ) ) {
				$out[ $key ] = $links[ $key ];
			}
		}
		return $out;
	}

	/** 9.14.0: the attributes the guest Compte link carries (bar and header nav alike). */
	public static function guest_attrs(): string {
		return ' data-dip-auth-open data-dl-open data-delicat-no-app="1" aria-haspopup="dialog" aria-controls="dip-identity-modal"';
	}

	public static function render(): void {
		if ( self::$rendered || ! self::expected() ) {
			return;
		}
		self::$rendered = true;
		/* Zero on a cacheable document: the copy is shared with every other
		 * guest, and the inline corrector below restores the real count from
		 * WooCommerce's cookie before paint. */
		$shared = class_exists( 'Delicat_Builder_V9_Security', false )
			&& is_callable( array( 'Delicat_Builder_V9_Security', 'shared_document' ) )
			&& Delicat_Builder_V9_Security::shared_document();
		$count  = ( ! $shared && function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->cart ) ) ? (int) WC()->cart->get_cart_contents_count() : 0;
		$links  = self::links();
		?>
		<nav class="delicat-bottom-nav" data-delicat-bottom-nav aria-label="<?php esc_attr_e( 'Navigation principale', 'delicat-builder-v9' ); ?>">
			<?php foreach ( $links as $key => $link ) :
				$label     = $link['label'];
				$is_active = $link['active'];
				$class     = 'dbn-item dbn-item--' . $key . ( $is_active ? ' is-active' : '' ) . ( 'shop' === $key ? ' dbn-item--center' : '' );
				$guest_identity = $link['guest'];
				$item_url = $link['url']; ?>
				<a class="<?php echo esc_attr( $class ); ?>" data-dbn-key="<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $item_url ); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?><?php echo 'shop' === $key ? ' data-delicat-prefetch' : ''; ?><?php echo $guest_identity ? self::guest_attrs() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup ?>>
					<span class="dbn-icon<?php echo 'shop' === $key ? ' dbn-center' : ''; ?>" aria-hidden="true"><?php echo self::icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php if ( 'cart' === $key ) : ?><i class="dbn-badge" data-dbn-cart-count<?php echo $count > 0 ? '' : ' hidden'; ?>><?php echo (int) $count; ?></i><?php endif; ?></span>
					<span class="dbn-label"><?php echo esc_html( $label ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<script>(function(){var nav=document.querySelector('[data-delicat-bottom-nav]');if(!nav)return;var badge=nav.querySelector('[data-dbn-cart-count]');var src=document.querySelector('[data-dsb8-cart-count]');
		/* RC32: a cached copy may carry a stale count. WooCommerce's cookie is a flag, not a count:
		   it can only prove the cart is empty (9.3 fix: the flag value "1" used to be painted as a count). */
		var m=document.cookie.match(/(?:^|; )woocommerce_items_in_cart=(\d+)/);var empty=!m||parseInt(m[1],10)===0;
		if(src&&empty&&(parseInt(src.textContent,10)||0)!==0){src.textContent='0';src.hidden=true;var list=document.querySelector('.dsb8-cart-list');var foot=document.querySelector('.dsb8-cart-panel__foot');if(list){list.innerHTML='<div class="dsb8-cart-empty">Votre panier est vide.</div>';}if(foot){foot.hidden=true;}}
		if(!badge||!src||!window.MutationObserver)return;var sync=function(){var n=parseInt(src.textContent,10)||0;badge.textContent=String(n);badge.hidden=!(n>0)||src.hidden;};new MutationObserver(sync).observe(src,{childList:true,characterData:true,subtree:true,attributes:true,attributeFilter:['hidden']});sync();}());</script>
		<?php
	}
}
