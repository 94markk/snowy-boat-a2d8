<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Bottom Nav (RC30) — the mobile navigation bar, rebuilt.
 *
 * Floating pill with five destinations (Accueil, Wallet, Boutique as the raised
 * centre action, Panier with its live count, Compte). Phones and tablets only
 * (≤820px); never on the cart/checkout/thank-you flow where the checkout dock
 * owns the bottom edge. Body carries `delicat-shell-mobile-nav-active` so the
 * product dock, the sheets and the chat launcher keep their existing offsets.
 */
final class Delicat_Builder_V9_Bottom_Nav {
	private static bool $booted   = false;
	private static bool $rendered = false;

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
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return false;
		}
		return (bool) apply_filters( 'delicat_builder_v9_bottom_nav', true );
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::expected() ) {
			$classes[] = 'delicat-shell-mobile-nav-active';
			$classes[] = 'delicat-bottom-nav-active';
		}
		return array_values( array_unique( $classes ) );
	}

	public static function assets(): void {
		if ( ! self::expected() ) {
			return;
		}
		/* The release-built storefront chrome already contains bottom-nav.css. */
		if ( ! wp_style_is( 'delicat-builder-v9-storefront-chrome', 'enqueued' ) ) {
			wp_enqueue_style( 'delicat-builder-v9-bottom-nav', DELICAT_BUILDER_V9_URL . 'assets/css/bottom-nav.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
	}

	private static function icon( string $name ): string {
		$paths = array(
			'home'    => '<path d="M3.5 11.2 12 4l8.5 7.2V20a1 1 0 0 1-1 1h-5v-6H9.5v6h-5a1 1 0 0 1-1-1z"/>',
			'wallet'  => '<rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18M15.5 14.5h2.5"/>',
			'shop'    => '<path d="M4 9.5 5.2 5h13.6L20 9.5M4 9.5v9A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5v-9M4 9.5c0 1.4 1.1 2.5 2.5 2.5S9 10.9 9 9.5c0 1.4 1.1 2.5 2.5 2.5S14 10.9 14 9.5c0 1.4 1.1 2.5 2.5 2.5S19 10.9 19 9.5M9.5 20v-5h5v5"/>',
			'cart'    => '<path d="M3.5 4.5h2l1.7 9.1a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20.5 8H6.2"/><circle cx="9.8" cy="19" r="1.2"/><circle cx="17.8" cy="19" r="1.2"/>',
			'account' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-5 3.3-7.5 7.5-7.5s6.8 2.5 7.5 7.5"/>',
		);
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
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

	public static function render(): void {
		if ( self::$rendered || ! self::expected() ) {
			return;
		}
		self::$rendered = true;
		$urls   = self::urls();
		$active = self::active();
		/* Zero on a cacheable document: the copy is shared with every other
		 * guest, and the inline corrector below restores the real count from
		 * WooCommerce's cookie before paint. */
		$shared = class_exists( 'Delicat_Builder_V9_Security', false )
			&& is_callable( array( 'Delicat_Builder_V9_Security', 'shared_document' ) )
			&& Delicat_Builder_V9_Security::shared_document();
		$count  = ( ! $shared && function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->cart ) ) ? (int) WC()->cart->get_cart_contents_count() : 0;
		$items  = array(
			'home'    => __( 'Accueil', 'delicat-builder-v9' ),
			'wallet'  => __( 'Wallet', 'delicat-builder-v9' ),
			'shop'    => __( 'Boutique', 'delicat-builder-v9' ),
			'cart'    => __( 'Panier', 'delicat-builder-v9' ),
			'account' => __( 'Compte', 'delicat-builder-v9' ),
		);
		?>
		<nav class="delicat-bottom-nav" data-delicat-bottom-nav aria-label="<?php esc_attr_e( 'Navigation principale', 'delicat-builder-v9' ); ?>">
			<?php foreach ( $items as $key => $label ) :
				$is_active = ( $key === $active );
				$class     = 'dbn-item dbn-item--' . $key . ( $is_active ? ' is-active' : '' ) . ( 'shop' === $key ? ' dbn-item--center' : '' );
				$guest_identity = 'account' === $key && ! is_user_logged_in() && class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && Delicat_Builder_V9_Identity_Bridge::frontend_login_available();
				$item_url = $guest_identity ? Delicat_Builder_V9_Identity_Bridge::guest_login_url() : $urls[ $key ]; ?>
				<a class="<?php echo esc_attr( $class ); ?>" data-dbn-key="<?php echo esc_attr( $key ); ?>" href="<?php echo esc_url( $item_url ); ?>"<?php echo $is_active ? ' aria-current="page"' : ''; ?><?php echo 'shop' === $key ? ' data-delicat-prefetch' : ''; ?><?php echo $guest_identity ? ' data-dip-auth-open data-dl-open data-delicat-no-app="1" aria-haspopup="dialog" aria-controls="dip-identity-modal"' : ''; ?>>
					<span class="dbn-icon<?php echo 'shop' === $key ? ' dbn-center' : ''; ?>" aria-hidden="true"><?php echo self::icon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php if ( 'cart' === $key ) : ?><i class="dbn-badge" data-dbn-cart-count<?php echo $count > 0 ? '' : ' hidden'; ?>><?php echo (int) $count; ?></i><?php endif; ?></span>
					<span class="dbn-label"><?php echo esc_html( $label ); ?></span>
				</a>
			<?php endforeach; ?>
		</nav>
		<script>(function(){var nav=document.querySelector('[data-delicat-bottom-nav]');if(!nav)return;var badge=nav.querySelector('[data-dbn-cart-count]');var src=document.querySelector('[data-dsb8-cart-count]');
		/* RC32: a cached copy may carry a stale count; WooCommerce's cookie is the truth until fragments arrive. */
		var m=document.cookie.match(/(?:^|; )woocommerce_items_in_cart=(\d+)/);var cookieCount=m?parseInt(m[1],10):0;
		if(src&&(parseInt(src.textContent,10)||0)!==cookieCount){src.textContent=String(cookieCount);src.hidden=!(cookieCount>0);if(!cookieCount){var list=document.querySelector('.dsb8-cart-list');var foot=document.querySelector('.dsb8-cart-panel__foot');if(list){list.innerHTML='<div class="dsb8-cart-empty">Votre panier est vide.</div>';}if(foot){foot.hidden=true;}}}
		if(!badge||!src||!window.MutationObserver)return;var sync=function(){var n=parseInt(src.textContent,10)||0;badge.textContent=String(n);badge.hidden=!(n>0)||src.hidden;};new MutationObserver(sync).observe(src,{childList:true,characterData:true,subtree:true,attributes:true,attributeFilter:['hidden']});sync();}());</script>
		<?php
	}
}
