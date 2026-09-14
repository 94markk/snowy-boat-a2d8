<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purchase Studio — Woo-native Cart & Checkout (RC51.58).
 *
 * WooCommerce remains authoritative for sessions, cart validation, nonces,
 * coupons, totals, checkout fields, orders and payment gateways. This class
 * owns presentation only: classic-cart templates plus scoped classic/block
 * styling. It deliberately creates no order, checkout or payment endpoint.
 */
final class Delicat_Builder_V9_Purchase_Native {

	private static bool $booted                    = false;
	private static bool $cart_takeover             = false;
	private static bool $cart_block                = false;
	private static bool $checkout_shell            = false;
	private static bool $checkout_block            = false;
	private static bool $checkout_request          = false;
	private static bool $express                   = false;
	private static bool $context_resolved          = false;
	private static bool $cart_heading_rendered     = false;
	private static bool $checkout_heading_rendered = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/*
		 * The request router loads this class from its own `wp` callback. Adding
		 * another `wp` callback at that point is hook-order dependent, so resolve
		 * immediately when the action has already started. This was the RC51.53
		 * regression that let the older dcn layer or stock Woo template win.
		 */
		if ( did_action( 'wp' ) ) {
			self::detect_context();
		} else {
			add_action( 'wp', array( __CLASS__, 'detect_context' ), 26 );
		}

		/* Last presentation filter wins, including over a leftover standalone cart plugin. */
		add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_template' ), PHP_INT_MAX, 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 45 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enforce_single_owner_assets' ), PHP_INT_MAX );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), PHP_INT_MAX );
		add_filter( 'the_content', array( __CLASS__, 'classic_checkout_content' ), 99 );
		add_filter( 'render_block_woocommerce/cart', array( __CLASS__, 'render_cart_block' ), 20, 2 );
		add_filter( 'render_block_woocommerce/checkout', array( __CLASS__, 'render_checkout_block' ), 20, 2 );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'checkout_fields' ), PHP_INT_MAX );
		add_filter( 'woocommerce_enable_order_notes_field', array( __CLASS__, 'order_notes_enabled' ), PHP_INT_MAX );
		add_filter( 'woocommerce_form_field', array( __CLASS__, 'billing_subheading' ), PHP_INT_MAX, 4 );
		add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'gateway_title' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'gateway_description' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_get_privacy_policy_text', array( __CLASS__, 'privacy_policy_text' ), PHP_INT_MAX, 2 );
		add_filter( 'woocommerce_get_terms_and_conditions_checkbox_text', array( __CLASS__, 'terms_checkbox_text' ), PHP_INT_MAX );
		add_action( 'woocommerce_checkout_before_customer_details', array( __CLASS__, 'checkout_contact_heading' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'checkout_dock' ), 98 );
		/* RC19: quiet checkout + Delicat Wallet balance guard. */
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'drop_redundant_checkout_notices' ), 9 );
		add_filter( 'woocommerce_no_available_payment_methods_message', array( __CLASS__, 'no_payment_methods_message' ), PHP_INT_MAX );
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'wallet_guard_markup' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'wallet_modal' ), 99 );
	}

	/**
	 * RC19: the classic checkout prints every pending WooCommerce notice above
	 * the form. On the native checkout the "added to your cart" success notice
	 * (a leftover of the product page) is pure noise; drop only that one.
	 */
	public static function drop_redundant_checkout_notices(): void {
		self::ensure_context();
		if ( ! self::$checkout_shell || self::$checkout_block || ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
			return;
		}
		$notices = wc_get_notices();
		if ( ! is_array( $notices ) || empty( $notices['success'] ) || ! is_array( $notices['success'] ) ) {
			return;
		}
		$kept = array();
		foreach ( $notices['success'] as $notice ) {
			$text  = is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice;
			$plain = strtolower( wp_strip_all_tags( $text ) );
			if (
				false !== strpos( $plain, 'à votre panier' )
				|| false !== strpos( $plain, 'added to your cart' )
				|| false !== strpos( $plain, 'to your cart' )
			) {
				continue;
			}
			$kept[] = $notice;
		}
		if ( count( $kept ) !== count( $notices['success'] ) ) {
			$notices['success'] = $kept;
			wc_set_notices( $notices );
		}
	}

	/** French, wallet-aware replacement for Woo's English "no payment methods" notice. */
	public static function no_payment_methods_message( $message ) {
		if ( ! self::checkout_contract_active() ) {
			return $message;
		}
		return __( 'Aucun mode de paiement disponible pour ce montant. Rechargez votre Delicat Wallet pour régler la totalité de la commande.', 'delicat-builder-v9' );
	}

	/** Delicat Wallet recharge destination: the /my-wallet/ page, else TeraWallet's account endpoint. */
	public static function wallet_url(): string {
		$page = get_page_by_path( 'my-wallet', OBJECT, 'page' );
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			$url = get_permalink( $page );
			if ( is_string( $url ) && '' !== $url ) {
				return $url . '#recharge';
			}
		}
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( 'woo-wallet' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account/' );
	}

	private static function plain_money( float $amount ): string {
		$html = function_exists( 'wc_price' ) ? (string) wc_price( $amount ) : number_format( $amount, 2 );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?: $text );
	}

	/**
	 * Wallet balance versus the real order total. TeraWallet's partial payment
	 * books the wallet share as a negative "Via wallet" fee, so the cart total
	 * alone would understate what the client must actually cover.
	 * Returns null when the guard does not apply (guest, no wallet, no cart).
	 */
	private static function wallet_state(): ?array {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! function_exists( 'woo_wallet' ) ) {
			return null;
		}
		try {
			$wc = WC();
			if ( ! is_object( $wc ) || ! is_object( $wc->cart ) || ! is_callable( array( $wc->cart, 'get_total' ) ) ) {
				return null;
			}
			$wallet = woo_wallet();
			if ( ! is_object( $wallet ) || ! isset( $wallet->wallet ) || ! is_callable( array( $wallet->wallet, 'get_wallet_balance' ) ) ) {
				return null;
			}
			$balance = (float) $wallet->wallet->get_wallet_balance( get_current_user_id(), 'edit' );
			$total   = (float) $wc->cart->get_total( 'edit' );
			if ( is_callable( array( $wc->cart, 'get_fees' ) ) ) {
				foreach ( (array) $wc->cart->get_fees() as $fee ) {
					if ( ! is_object( $fee ) ) {
						continue;
					}
					$amount = (float) ( $fee->amount ?? 0 );
					$label  = strtolower( (string) ( $fee->name ?? '' ) . ' ' . (string) ( $fee->id ?? '' ) );
					if ( $amount < 0 && ( false !== strpos( $label, 'wallet' ) || false !== strpos( $label, 'portefeuille' ) ) ) {
						$total += abs( $amount );
					}
				}
			}
		} catch ( Throwable $error ) {
			unset( $error );
			return null;
		}
		if ( $total <= 0 ) {
			return null;
		}
		$short = max( 0.0, $total - $balance );
		return array(
			'balance'      => $balance,
			'total'        => $total,
			'short'        => $short,
			'insufficient' => $short > 0.009,
		);
	}

	/** RC20: the balance guard (sheet + interceptor) is shared by the checkout page and the express sheet. */
	public static function enqueue_wallet_guard(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( is_file( DELICAT_BUILDER_V9_DIR . 'assets/css/wallet-guard.css' ) ) {
			wp_enqueue_style( 'delicat-builder-v9-wallet-guard', DELICAT_BUILDER_V9_URL . 'assets/css/wallet-guard.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
		if ( is_file( DELICAT_BUILDER_V9_DIR . 'assets/js/wallet-guard.js' ) ) {
			wp_enqueue_script( 'delicat-builder-v9-wallet-guard', DELICAT_BUILDER_V9_URL . 'assets/js/wallet-guard.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		}
	}

	/** Refreshed with Woo's payment fragment on every update_order_review. */
	public static function wallet_guard_markup(): void {
		if ( ! self::checkout_contract_active() ) {
			return;
		}
		$state = self::wallet_state();
		if ( null === $state ) {
			return;
		}
		printf(
			'<div class="dpn-wallet-guard" data-dpn-wallet-guard data-insufficient="%1$s" data-balance="%2$s" data-total="%3$s" data-short="%4$s" data-balance-text="%5$s" data-total-text="%6$s" data-short-text="%7$s" data-wallet-url="%8$s" hidden></div>',
			$state['insufficient'] ? '1' : '0',
			esc_attr( (string) $state['balance'] ),
			esc_attr( (string) $state['total'] ),
			esc_attr( (string) $state['short'] ),
			esc_attr( self::plain_money( (float) $state['balance'] ) ),
			esc_attr( self::plain_money( (float) $state['total'] ) ),
			esc_attr( self::plain_money( (float) $state['short'] ) ),
			esc_url( self::wallet_url() )
		);
	}

	/**
	 * Smooth bottom-sheet shown by purchase-native.js when the client taps
	 * "Passer la commande" with an insufficient Delicat Wallet balance.
	 * Presentation only: the click is stopped before WooCommerce submits, and
	 * the sheet leads to the wallet recharge page.
	 */
	public static function wallet_modal(): void {
		self::ensure_context();
		if ( ! is_user_logged_in() || ( ! self::$express && ( ! self::$checkout_shell || self::$checkout_block ) ) ) {
			return;
		}
		?>
		<div class="dpn-wallet-modal" data-dpn-wallet-modal role="dialog" aria-modal="true" aria-labelledby="dpn-wallet-modal-title" hidden>
			<div class="dpn-wallet-modal-backdrop" data-dpn-wallet-close></div>
			<div class="dpn-wallet-modal-card" tabindex="-1" data-dpn-wallet-card>
				<span class="dpn-wallet-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18M15.5 14.5h2.5"/></svg></span>
				<h2 id="dpn-wallet-modal-title"><?php esc_html_e( 'Solde insuffisant', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Le solde de votre compte est insuffisant, veuillez recharger votre compte.', 'delicat-builder-v9' ); ?></p>
				<dl class="dpn-wallet-modal-figures">
					<div><dt><?php esc_html_e( 'Solde actuel', 'delicat-builder-v9' ); ?></dt><dd data-dpn-wallet-balance></dd></div>
					<div><dt><?php esc_html_e( 'Total à payer', 'delicat-builder-v9' ); ?></dt><dd data-dpn-wallet-total></dd></div>
					<div class="is-short"><dt><?php esc_html_e( 'Montant manquant', 'delicat-builder-v9' ); ?></dt><dd data-dpn-wallet-short></dd></div>
				</dl>
				<a class="dpn-wallet-modal-cta" data-dpn-wallet-link href="<?php echo esc_url( self::wallet_url() ); ?>"><?php esc_html_e( 'Recharger mon compte', 'delicat-builder-v9' ); ?><?php echo self::icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<button type="button" class="dpn-wallet-modal-close" data-dpn-wallet-close><?php esc_html_e( 'Fermer', 'delicat-builder-v9' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Activate only the field/gateway contract on WooCommerce's own classic
	 * checkout AJAX endpoints. Woo still owns the endpoint and request lifecycle.
	 */
	public static function activate_checkout_request(): void {
		self::$checkout_request = true;
	}

	/* ------------------------------------------------------------------ */
	/* RC20 express checkout (product page sheet)                          */
	/* ------------------------------------------------------------------ */

	/** The express sheet reuses the checkout contract; flag it so shared markup renders. */
	public static function mark_express(): void {
		self::$express          = true;
		self::$checkout_request = true;
	}

	public static function express_active(): bool {
		return self::$express;
	}

	public static function express_supported(): bool {
		return self::studio_enabled() && ! empty( self::settings()['native_checkout'] );
	}

	/** Same accent/action variables as the checkout page, scoped to the express document. */
	public static function express_presentation_css(): string {
		return self::presentation_css( 'body.dnp-express-enabled' );
	}

	/**
	 * WooCommerce's real checkout form (checkout/form-checkout.php with the
	 * store's fields, gateways, terms, nonce and place-order button) for the
	 * express sheet. Only presentation hooks that belong to the full checkout
	 * page are stood down while it renders: the coupon form, the login form
	 * and the page-level notice dump.
	 */
	public static function render_express_form(): string {
		if ( ! is_user_logged_in() || ! function_exists( 'WC' ) || ! function_exists( 'wc_get_template' ) ) {
			return '';
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! is_object( $wc->cart ) || ! is_callable( array( $wc, 'checkout' ) ) ) {
			return '';
		}
		self::mark_express();
		$stood_down = array();
		foreach ( array( 'woocommerce_checkout_coupon_form', 'woocommerce_checkout_login_form', 'woocommerce_output_all_notices' ) as $callback ) {
			$priority = has_action( 'woocommerce_before_checkout_form', $callback );
			if ( false !== $priority ) {
				remove_action( 'woocommerce_before_checkout_form', $callback, (int) $priority );
				$stood_down[ $callback ] = (int) $priority;
			}
		}
		$html = '';
		ob_start();
		try {
			if ( is_callable( array( $wc->cart, 'is_empty' ) ) && ! $wc->cart->is_empty() && is_callable( array( $wc->cart, 'calculate_totals' ) ) ) {
				$wc->cart->calculate_totals();
			}
			wc_get_template( 'checkout/form-checkout.php', array( 'checkout' => $wc->checkout() ) );
			$html = (string) ob_get_clean();
		} catch ( Throwable $error ) {
			ob_end_clean();
			unset( $error );
			$html = '';
		}
		foreach ( $stood_down as $callback => $priority ) {
			add_action( 'woocommerce_before_checkout_form', $callback, $priority );
		}
		return $html;
	}

	private static function settings(): array {
		if ( class_exists( 'Delicat_Builder_V9_Purchase_UI', false ) ) {
			return Delicat_Builder_V9_Purchase_UI::settings();
		}

		$saved = get_option( 'delicat_builder_v9_purchase_ui', array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'enabled'          => 0,
				'native_cart'      => 1,
				'native_checkout'  => 1,
				'compact_checkout' => 0,
				'accent_color'     => '#6d5dfc',
				'max_width'        => 1200,
			)
		);
	}

	private static function studio_enabled(): bool {
		if (
			! class_exists( 'WooCommerce' )
			|| (
				class_exists( 'Delicat_Builder_V9_Core', false )
				&& ! Delicat_Builder_V9_Core::is_enabled()
			)
		) {
			return false;
		}

		/*
		 * Compatibility Safe Mode quarantines optional Builder runtimes. The
		 * purchase-native layer is deliberately recovery-safe: it changes only
		 * markup/CSS and submits WooCommerce's real forms, nonces and URLs. Keeping
		 * it available prevents an old sticky Safe Mode flag from silently
		 * removing the merchant's cart/checkout design after an update.
		 */
		return ! empty( self::settings()['enabled'] );
	}

	/** Same Woo field contract must exist on both page render and AJAX submit. */
	private static function checkout_contract_active(): bool {
		if ( ! self::studio_enabled() || empty( self::settings()['native_checkout'] ) ) {
			return false;
		}
		if ( self::$checkout_request ) {
			return true;
		}

		self::ensure_context();
		return self::$checkout_shell && ! self::$checkout_block;
	}

	/** Detect the assigned Woo page's real renderer, not the active theme. */
	private static function page_is_block( string $type ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return false;
		}

		$page_id = absint( wc_get_page_id( $type ) );
		if ( $page_id < 1 ) {
			return false;
		}

		$post = get_post( $page_id );
		return $post instanceof WP_Post && has_block( 'woocommerce/' . $type, $post );
	}

	/**
	 * Resolve lazily as a safety net for unusual theme/filter ordering, so the
	 * legacy cart layer cannot win only because the wp action was observed late.
	 */
	private static function ensure_context(): void {
		if ( ! self::$context_resolved ) {
			self::detect_context();
		}
	}

	public static function detect_context(): void {
		self::$context_resolved = true;
		self::$cart_takeover    = false;
		self::$cart_block       = false;
		self::$checkout_shell   = false;
		self::$checkout_block   = false;

		if ( is_admin() || wp_doing_ajax() || ! self::studio_enabled() ) {
			return;
		}

		$settings = self::settings();
		$is_cart  = function_exists( 'is_cart' ) && is_cart();
		if ( $is_cart && ! empty( $settings['native_cart'] ) ) {
			self::$cart_block    = self::page_is_block( 'cart' );
			self::$cart_takeover = ! self::$cart_block;
		}

		$is_received = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! $is_received;
		if ( $is_checkout && ! empty( $settings['native_checkout'] ) ) {
			self::$checkout_shell = true;
			self::$checkout_block = self::page_is_block( 'checkout' );
		}

		self::stand_down_legacy_owner();
		self::stand_down_duplicate_checkout_login();
	}

	/** Signed-out visitor on the native classic checkout: WooCommerce cannot take the order (login required). */
	private static function guest_gate_active(): bool {
		if ( is_user_logged_in() || ! self::$checkout_shell || self::$checkout_block || ! function_exists( 'WC' ) ) {
			return false;
		}
		$checkout = is_object( WC() ) && is_callable( array( WC(), 'checkout' ) ) ? WC()->checkout() : null;
		if ( ! is_object( $checkout ) || ! is_callable( array( $checkout, 'is_registration_required' ) ) ) {
			return false;
		}
		/* Guest checkout allowed by WooCommerce settings: leave WooCommerce's own flow untouched. */
		return (bool) $checkout->is_registration_required() && ( ! is_callable( array( $checkout, 'is_registration_enabled' ) ) || ! $checkout->is_registration_enabled() );
	}

	/**
	 * RC28: the gate's own styles travel inline with its markup. Signed-out
	 * visitors can be served pages whose external stylesheets a guest-only
	 * optimizer (LiteSpeed combine / unique-CSS) has rewritten or stripped;
	 * a 2 KB inline sheet cannot be separated from the HTML it styles.
	 */
	private static function guest_gate_css(): string {
		return 'body.dpn-checkout{--dpn-bg:#eef0f4;--dpn-card:#fff;--dpn-text:#171922;--dpn-muted:#737887;--dpn-line:#e5e7ed;--dpn-soft:#f7f8fa;--dpn-accent:#6d5dfc;--dpn-accent-rgb:109,93,252;--dpn-action-start:#5f51ef;--dpn-action-end:#ed23b2;--dpn-max:1200px;background:var(--dpn-bg)!important;color:var(--dpn-text)}'
			. 'body.dpn-checkout .delicat-native-page-main{box-sizing:border-box;width:min(var(--dpn-max,1200px),calc(100% - 28px));margin-inline:auto;padding-top:22px;padding-bottom:max(56px,calc(32px + env(safe-area-inset-bottom)))}'
			. 'body.dpn-checkout .delicat-native-page-main>h1{display:none!important}'
			. '.dpn-guest-gate,.dpn-guest-gate *{box-sizing:border-box;font-family:var(--delicat-font,Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif)}'
			. '.dpn-guest-gate{width:min(var(--dpn-max,1200px),100%);margin:0 auto 18px;color:var(--dpn-text,#171922)}'
			. '.dpn-guest-gate .dpn-checkout-title{margin:0 0 14px;padding:0}'
			. '.dpn-guest-gate .dpn-checkout-title h1{margin:0;font-size:clamp(25px,3.2vw,30px);font-weight:800;line-height:1.08;letter-spacing:-.035em;color:var(--dpn-text,#171922)}'
			. '.dpn-guest-gate .dpn-checkout-title p{margin:6px 0 0;font-size:14px;line-height:1.4;color:var(--dpn-muted,#737887)}'
			. '.dpn-gate-card{width:100%;margin:0 auto;padding:24px 18px 20px;border:1px solid var(--dpn-line,#e5e7ed);border-radius:22px;background:var(--dpn-card,#fff);text-align:center;box-shadow:0 10px 28px rgba(16,24,40,.07)}'
			. '.dpn-gate-mark{display:grid;place-items:center;width:48px;height:48px;margin:0 auto 12px;border-radius:16px;background:rgba(var(--dpn-accent-rgb,109,93,252),.12);color:var(--dpn-accent,#6d5dfc)}'
			. '.dpn-gate-mark svg{display:block!important;width:22px!important;height:22px!important;max-width:22px!important;max-height:22px!important;min-width:22px!important;min-height:22px!important;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}'
			. '.dpn-gate-card h2{margin:0 0 7px;font-size:20px;font-weight:850;letter-spacing:-.02em;color:var(--dpn-text,#171922)}'
			. '.dpn-gate-card>p{margin:0 auto 16px;max-width:34em;font-size:14px;line-height:1.5;color:var(--dpn-muted,#737887)}'
			. '.dpn-gate-cart{margin:0 0 16px;padding:4px 13px;list-style:none;text-align:left;border:1px solid var(--dpn-line,#e5e7ed);border-radius:15px;background:var(--dpn-soft,#f7f8fa)}'
			. '.dpn-gate-cart li{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:0;padding:9px 0;border-bottom:1px dashed var(--dpn-line,#e5e7ed);font-size:13px;color:var(--dpn-text,#171922)}'
			. '.dpn-gate-cart li:last-child{border-bottom:0}.dpn-gate-cart li em{font-style:normal;color:var(--dpn-muted,#737887)}.dpn-gate-cart li strong{white-space:nowrap}'
			. '.dpn-gate-cart li.is-total{font-weight:800}.dpn-gate-cart li.is-total strong{font-size:16px;color:var(--dpn-accent,#6d5dfc)}'
			. '.dpn-gate-cta{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;min-height:52px;margin:0;padding:11px 17px;border:0;border-radius:16px;background:linear-gradient(135deg,var(--dpn-action-start,#5f51ef),var(--dpn-action-end,#ed23b2));color:#fff!important;font:inherit;font-size:15px;font-weight:800;line-height:1.2;text-decoration:none!important;cursor:pointer;box-shadow:0 10px 22px rgba(var(--dpn-accent-rgb,109,93,252),.24)}'
			. '.dpn-gate-cta svg{display:block!important;width:18px!important;height:18px!important;max-width:18px!important;max-height:18px!important;flex:0 0 18px!important;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'
			. '.dpn-gate-secondary{display:inline-block;margin:12px 0 0;font-size:13px;font-weight:700;color:var(--dpn-accent,#6d5dfc);text-decoration:none}'
			. '.dpn-gate-trust{display:flex;justify-content:center;gap:5px 12px;flex-wrap:wrap;margin:12px 0 0;font-size:11px;line-height:1.35;color:var(--dpn-muted,#737887)}'
			. '.dpn-gate-trust span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}'
			. '.dpn-gate-trust span>svg{display:block!important;width:12px!important;height:12px!important;max-width:12px!important;max-height:12px!important;min-width:12px!important;min-height:12px!important;flex:0 0 12px!important;fill:none!important;stroke:currentColor!important;stroke-width:1.8!important;stroke-linecap:round;stroke-linejoin:round}'
			. '@media(min-width:641px){.dpn-gate-card{max-width:500px;padding:28px 28px 22px}}'
			. '@media(min-width:641px) and (max-width:1024px){body.dpn-checkout .delicat-native-page-main{width:min(760px,calc(100% - 40px));padding-top:18px}.dpn-guest-gate{margin-bottom:12px}.dpn-gate-card{max-width:480px}}';
	}

	/** Presentation only: the sign-in itself is Delicat Identity's modal (or the account page when no modal). */
	private static function guest_gate_markup(): string {
		$modal   = self::identity_checkout_owner();
		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		$lines   = array();
		$total   = '';
		if ( function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->cart ) ) {
			foreach ( (array) WC()->cart->get_cart() as $item ) {
				$product = is_array( $item ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ? $item['data'] : null;
				if ( ! $product ) {
					continue;
				}
				$lines[] = array( 'name' => $product->get_name(), 'qty' => (int) ( $item['quantity'] ?? 1 ), 'total' => (string) WC()->cart->get_product_subtotal( $product, (int) ( $item['quantity'] ?? 1 ) ) );
			}
			$total = (string) WC()->cart->get_total();
		}
		ob_start();
		?>
		<style id="dpn-guest-gate-css"><?php echo self::guest_gate_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-owned stylesheet. ?></style>
		<section class="dpn-checkout-intro dpn-guest-gate" aria-labelledby="dpn-checkout-title">
			<header class="dpn-checkout-title">
				<h1 id="dpn-checkout-title"><?php esc_html_e( 'Finaliser la commande', 'delicat-builder-v9' ); ?></h1>
				<p><?php esc_html_e( 'Connectez-vous pour payer avec votre Delicat Wallet.', 'delicat-builder-v9' ); ?></p>
			</header>
			<article class="dpn-gate-card">
				<span class="dpn-gate-mark" aria-hidden="true"><?php echo self::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<h2><?php esc_html_e( 'Connexion requise', 'delicat-builder-v9' ); ?></h2>
				<p><?php esc_html_e( 'Votre panier est conservé. Connectez-vous avec Delicat Identity pour finaliser votre commande en toute sécurité.', 'delicat-builder-v9' ); ?></p>
				<?php if ( $lines ) : ?>
					<ul class="dpn-gate-cart" aria-label="<?php esc_attr_e( 'Votre panier', 'delicat-builder-v9' ); ?>">
						<?php foreach ( $lines as $line ) : ?>
							<li><span><?php echo esc_html( $line['name'] ); ?><?php if ( $line['qty'] > 1 ) : ?> <em>× <?php echo (int) $line['qty']; ?></em><?php endif; ?></span><strong><?php echo wp_kses_post( $line['total'] ); ?></strong></li>
						<?php endforeach; ?>
						<li class="is-total"><span><?php esc_html_e( 'Total', 'delicat-builder-v9' ); ?></span><strong><?php echo wp_kses_post( $total ); ?></strong></li>
					</ul>
				<?php endif; ?>
				<?php if ( $modal ) : ?>
					<button type="button" class="dpn-gate-cta" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal"><?php esc_html_e( 'Se connecter avec Delicat Identity', 'delicat-builder-v9' ); ?><?php echo self::icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<?php else : ?>
					<a class="dpn-gate-cta" href="<?php echo esc_url( $account ); ?>"><?php esc_html_e( 'Se connecter', 'delicat-builder-v9' ); ?><?php echo self::icon( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php endif; ?>
				<a class="dpn-gate-secondary" href="<?php echo esc_url( self::shop_url() ); ?>"><?php esc_html_e( 'Continuer vos achats', 'delicat-builder-v9' ); ?></a>
				<p class="dpn-gate-trust"><span><?php echo self::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Paiement sécurisé WooCommerce', 'delicat-builder-v9' ); ?></span><span><?php echo self::icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Délai selon le produit', 'delicat-builder-v9' ); ?></span></p>
			</article>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/** Identity owns the one visible guest login surface when it is available. */
	private static function identity_checkout_owner(): bool {
		return self::$checkout_shell
			&& ! self::$checkout_block
			&& ! is_user_logged_in()
			&& class_exists( 'Delicat_Builder_V9_Identity_Bridge', false )
			&& is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'frontend_login_available' ) )
			&& Delicat_Builder_V9_Identity_Bridge::frontend_login_available();
	}

	/** Remove only Woo's duplicate login toggle; Identity keeps authentication. */
	private static function stand_down_duplicate_checkout_login(): void {
		if ( self::guest_gate_active() ) {
			/* The gate is the only guest surface: no Woo login toggle, no Identity prompt/button block, no coupon form. */
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
			remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );
			if ( class_exists( 'DIP_Woo', false ) ) {
				remove_action( 'woocommerce_before_checkout_form', array( 'DIP_Woo', 'render_checkout_prompt' ), 8 );
			}
			return;
		}
		if ( ! self::identity_checkout_owner() ) {
			return;
		}

		remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10 );
	}

	/** Remove the obsolete table-morph hooks once this request has one owner. */
	private static function stand_down_legacy_owner(): void {
		if ( ! class_exists( 'Delicat_Builder_V9_Storefront_Fix', false ) ) {
			return;
		}

		$legacy = 'Delicat_Builder_V9_Storefront_Fix';
		if ( self::$cart_takeover || self::$cart_block ) {
			remove_action( 'woocommerce_before_cart', array( $legacy, 'render_cart_toolbar' ), 1 );
			remove_action( 'woocommerce_cart_is_empty', array( $legacy, 'render_empty_cart' ), 1 );
			remove_filter( 'woocommerce_cart_item_name', array( $legacy, 'cart_item_name' ), 20 );
			remove_filter( 'woocommerce_cart_item_remove_link', array( $legacy, 'cart_item_remove_link' ), 20 );
		}
	}

	public static function cart_takeover(): bool {
		self::ensure_context();
		return self::$cart_takeover;
	}

	public static function cart_surface_active(): bool {
		self::ensure_context();
		return self::$cart_takeover || self::$cart_block;
	}

	public static function cart_block_active(): bool {
		self::ensure_context();
		return self::$cart_block;
	}

	public static function checkout_shell_active(): bool {
		self::ensure_context();
		return self::$checkout_shell;
	}

	public static function checkout_block_active(): bool {
		self::ensure_context();
		return self::$checkout_shell && self::$checkout_block;
	}

	/** Classic cart only; Cart Blocks remain entirely Store-API controlled. */
	public static function locate_template( $template, $template_name, $template_path = '', $default_path = '' ) {
		unset( $template_path, $default_path );
		self::ensure_context();
		$wc = function_exists( 'WC' ) ? WC() : null;
		$cart_ready = is_object( $wc ) && is_object( $wc->cart )
			&& is_callable( array( $wc->cart, 'get_cart' ) )
			&& is_callable( array( $wc->cart, 'get_cart_contents_count' ) );
		if ( ! self::$cart_takeover || ! $cart_ready || ! is_string( $template_name ) ) {
			return $template;
		}

		$map = array(
			'cart/cart.php'       => 'templates/purchase/cart.php',
			'cart/cart-empty.php' => 'templates/purchase/cart-empty.php',
		);
		if ( ! isset( $map[ $template_name ] ) ) {
			return $template;
		}

		$native = DELICAT_BUILDER_V9_DIR . $map[ $template_name ];
		return is_readable( $native ) ? $native : $template;
	}

	/** Remove every older cart/checkout style owner before adding one native owner. */
	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		self::ensure_context();

		if ( self::$cart_takeover || self::$cart_block ) {
			$classes = array_diff(
				$classes,
				array( 'delicat-native-cart', 'delicat-purchase-ui', 'delicat-purchase-cart', 'dcn-cart-empty' )
			);
			$classes[] = 'dpn-cart';
			$classes[] = self::$cart_block ? 'dpn-cart-block' : 'dpn-cart-classic';
		}

		if ( self::$checkout_shell ) {
			$classes = array_diff(
				$classes,
				array( 'delicat-native-checkout', 'delicat-purchase-ui', 'delicat-purchase-checkout', 'delicat-purchase-checkout-compact', 'dcn-checkout-login-normalized' )
			);
			$classes[] = 'dpn-checkout';
			$classes[] = self::$checkout_block ? 'dpn-checkout-block' : 'dpn-checkout-classic';
			if ( ! empty( self::settings()['compact_checkout'] ) ) {
				$classes[] = 'dpn-checkout-compact';
			}
			if ( self::minimal_checkout_active() ) {
				$classes[] = 'dpn-checkout-minimal';
			}
			if ( self::identity_checkout_owner() ) {
				$classes[] = 'dpn-checkout-identity-owner';
			}
		}

		if (
			( self::$cart_takeover || self::$cart_block || self::$checkout_shell )
			&& Delicat_Builder_V9_Core::is_safe_mode()
		) {
			$classes[] = 'dpn-safe-recovery';
		}

		return array_values( array_unique( $classes ) );
	}

	/**
	 * Apply the merchant's saved Purchase Studio width/accent to the native
	 * surface. RC51.53 loaded the stylesheet but dropped these saved values.
	 */
	private static function presentation_css( string $selector = 'body.dpn-cart,body.dpn-checkout' ): string {
		$settings = self::settings();
		$accent   = sanitize_hex_color( (string) ( $settings['accent_color'] ?? '#f47721' ) ) ?: '#f47721';
		$width    = absint( $settings['max_width'] ?? 1200 );
		if ( ! in_array( $width, array( 1080, 1200, 1320, 1440 ), true ) ) {
			$width = 1200;
		}

		$red   = hexdec( substr( $accent, 1, 2 ) );
		$green = hexdec( substr( $accent, 3, 2 ) );
		$blue  = hexdec( substr( $accent, 5, 2 ) );
		$soft  = sprintf(
			'#%02x%02x%02x',
			(int) round( $red + ( 255 - $red ) * 0.24 ),
			(int) round( $green + ( 255 - $green ) * 0.24 ),
			(int) round( $blue + ( 255 - $blue ) * 0.24 )
		);
		$luminance   = ( 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue ) / 255;
		$action_text = $luminance > 0.68 ? '#171922' : '#ffffff';
		$action_start = $soft;
		$action_end   = $accent;
		if ( '#6d5dfc' === strtolower( $accent ) ) {
			/* Preserve the established Delicat purple-to-pink brand treatment. */
			$action_start = '#5f51ef';
			$action_end   = '#ed23b2';
		}

		return sprintf(
			$selector . '{--dpn-accent:%1$s;--dpn-accent-rgb:%2$d,%3$d,%4$d;--dpn-orange:%1$s;--dpn-orange-soft:%5$s;--dpn-action-text:%6$s;--dpn-max:%7$dpx;--dpn-action-start:%8$s;--dpn-action-end:%9$s}',
			$accent,
			$red,
			$green,
			$blue,
			$soft,
			$action_text,
			$width,
			$action_start,
			$action_end
		);
	}

	public static function enqueue_assets(): void {
		self::ensure_context();
		if ( ! self::$cart_takeover && ! self::$cart_block && ! self::$checkout_shell ) {
			return;
		}

		/*
		 * RC89: the signed-out checkout gate is a complete inline surface.
		 * Do not download/parse the ~100 KB purchase CSS+JS bundle for a page
		 * that cannot submit a WooCommerce order until Identity login succeeds.
		 */
		if ( self::guest_gate_active() ) {
			return;
		}

		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/purchase-native.css';
		if ( is_file( $css ) ) {
			wp_enqueue_style(
				'delicat-builder-v9-purchase-native',
				DELICAT_BUILDER_V9_URL . 'assets/css/purchase-native.css',
				array(),
				DELICAT_BUILDER_V9_VERSION
			);
			wp_add_inline_style( 'delicat-builder-v9-purchase-native', self::presentation_css() );
		}

		/* Checkout reference layer is intentionally last so theme/global styles cannot win. */
		if ( self::$checkout_shell ) {
			/* 9.1 RC1: checkout-reference layer merged into purchase-native.css. */
		}

		$js = DELICAT_BUILDER_V9_DIR . 'assets/js/purchase-native.js';
		if ( ! is_file( $js ) ) {
			return;
		}

		wp_enqueue_script(
			'delicat-builder-v9-purchase-native',
			DELICAT_BUILDER_V9_URL . 'assets/js/purchase-native.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			true
		);
		if ( self::$checkout_shell ) {
			self::enqueue_wallet_guard();
		}
		wp_add_inline_script(
			'delicat-builder-v9-purchase-native',
			'window.DelicatPurchaseNative=' . wp_json_encode(
				array(
					'clearConfirm'  => __( 'Vider tout le panier ?', 'delicat-builder-v9' ),
					'updateDelay'   => 450,
					'paymentTitle'  => __( 'Mode de paiement', 'delicat-builder-v9' ),
					'walletTitle'   => __( 'Delicat Wallet', 'delicat-builder-v9' ),
					'walletBalance' => __( 'Solde disponible', 'delicat-builder-v9' ),
					'walletAfter'   => __( 'Solde après cet achat', 'delicat-builder-v9' ),
					'emailLabel'    => __( 'Adresse e-mail', 'delicat-builder-v9' ),
					'emailHelp'     => __( 'Le reçu et le code du produit sont envoyés à cette adresse.', 'delicat-builder-v9' ),
					'nameLabel'     => __( 'Nom complet', 'delicat-builder-v9' ),
					'phoneLabel'    => __( 'Numéro WhatsApp', 'delicat-builder-v9' ),
					'phoneHelp'     => __( 'Pour vous joindre rapidement en cas de souci sur la livraison.', 'delicat-builder-v9' ),
					'billingTitle'  => __( 'Détails de facturation', 'delicat-builder-v9' ),
					'serviceFee'    => __( 'Frais de service', 'delicat-builder-v9' ),
					'free'          => __( 'Gratuit', 'delicat-builder-v9' ),
				)
			) . ';',
			'before'
		);
	}

	/** A late safety net for cached/legacy enqueue callbacks. */
	public static function enforce_single_owner_assets(): void {
		self::ensure_context();
		if ( ! self::$cart_takeover && ! self::$cart_block && ! self::$checkout_shell ) {
			return;
		}

		wp_dequeue_script( 'delicat-builder-v9-storefront-commerce' );
		if ( self::$cart_takeover || self::$cart_block ) {
			wp_dequeue_style( 'delicat-builder-v9-rc-cart-block' );
		}
		if ( self::$checkout_shell ) {
			wp_dequeue_style( 'delicat-builder-v9-rc-checkout-block' );
		}
	}

	private static function item_count(): int {
		return ( function_exists( 'WC' ) && WC()->cart )
			? absint( WC()->cart->get_cart_contents_count() )
			: 0;
	}

	private static function heading_markup( string $type ): string {
		if ( 'checkout' === $type ) {
			return self::checkout_intro_markup();
		}

		$count = self::item_count();
		$url   = self::shop_url();
		$title = __( 'Panier', 'delicat-builder-v9' );
		$label = __( 'Continuer vos achats', 'delicat-builder-v9' );

		$count_text = sprintf(
			_n( '%d article', '%d articles', $count, 'delicat-builder-v9' ),
			$count
		);

		return '<header class="dpn-head dpn-head-' . esc_attr( $type ) . '"><a class="dpn-back" href="'
			. esc_url( $url ) . '" aria-label="' . esc_attr( $label ) . '">' . self::icon( 'back' ) . '</a>'
			. '<div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $count_text ) . '</p></div></header>';
	}

	/** Two-letter customer mark without requiring the mbstring extension. */
	private static function customer_initials( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( wp_strip_all_tags( $name ) ) );
		$parts = is_array( $parts ) ? array_values( array_filter( $parts ) ) : array();
		$marks = '';
		foreach ( $parts as $part ) {
			if ( ! preg_match( '/\p{L}/u', $part ) ) {
				continue;
			}
			$marks .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1, 'UTF-8' ) : substr( $part, 0, 1 );
			if ( 2 <= ( function_exists( 'mb_strlen' ) ? mb_strlen( $marks, 'UTF-8' ) : strlen( $marks ) ) ) {
				break;
			}
		}
		if ( '' === $marks ) {
			$marks = 'DS';
		} elseif ( 1 === ( function_exists( 'mb_strlen' ) ? mb_strlen( $marks, 'UTF-8' ) : strlen( $marks ) ) ) {
			$second = function_exists( 'mb_substr' ) ? mb_substr( trim( $name ), 1, 1, 'UTF-8' ) : substr( trim( $name ), 1, 1 );
			$marks .= $second;
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $marks, 'UTF-8' ) : strtoupper( $marks );
	}

	/**
	 * Presentation-only checkout introduction. Customer data is read from the
	 * current authenticated account and escaped; no checkout value is submitted
	 * from this card. The coupon control delegates to Woo's native coupon form.
	 */
	private static function checkout_intro_markup(): string {
		$user       = wp_get_current_user();
		$logged_in  = $user instanceof WP_User && $user->exists();
		$coupons    = ! function_exists( 'wc_coupons_enabled' ) || wc_coupons_enabled();
		$name       = $logged_in ? ( $user->display_name ?: $user->user_login ) : __( 'Commande sécurisée', 'delicat-builder-v9' );
		$email      = $logged_in ? (string) $user->user_email : __( 'Validation et paiement gérés par WooCommerce', 'delicat-builder-v9' );
		$identity   = $logged_in ? self::customer_initials( $name ) : '';
		$status     = $logged_in ? __( 'Connecté', 'delicat-builder-v9' ) : __( 'Invité', 'delicat-builder-v9' );
		$steps      = array(
			array( 'done', __( 'Panier', 'delicat-builder-v9' ) ),
			array( 'done', __( 'Coordonnées', 'delicat-builder-v9' ) ),
			array( 'current', __( 'Paiement', 'delicat-builder-v9' ) ),
		);

		ob_start();
		?>
		<section class="dpn-checkout-intro" aria-labelledby="dpn-checkout-title">
			<header class="dpn-checkout-title">
				<h1 id="dpn-checkout-title"><?php esc_html_e( 'Finaliser la commande', 'delicat-builder-v9' ); ?></h1>
				<p><?php esc_html_e( 'Livraison après confirmation du paiement, selon le produit.', 'delicat-builder-v9' ); ?></p>
			</header>
			<ol class="dpn-checkout-steps" aria-label="<?php esc_attr_e( 'Étapes de commande', 'delicat-builder-v9' ); ?>">
				<?php foreach ( $steps as $index => $step ) : ?>
					<li class="is-<?php echo esc_attr( $step[0] ); ?>"<?php echo 'current' === $step[0] ? ' aria-current="step"' : ''; ?>>
						<span class="dpn-step-mark"><?php echo 'done' === $step[0] ? self::icon( 'check' ) : esc_html( (string) ( $index + 1 ) ); ?></span>
						<strong><?php echo esc_html( $step[1] ); ?></strong>
					</li>
				<?php endforeach; ?>
			</ol>
			<article class="dpn-account-card<?php echo $logged_in ? ' is-connected' : ' is-guest'; ?>">
				<div class="dpn-account-row">
					<span class="dpn-account-mark" aria-hidden="true"><?php echo $logged_in ? esc_html( $identity ) : self::icon( 'lock' ); ?></span>
					<span class="dpn-account-copy"><strong><?php echo esc_html( $name ); ?></strong><small><?php echo esc_html( $email ); ?></small></span>
					<span class="dpn-account-status"><?php echo esc_html( $status ); ?></span>
				</div>
				<?php if ( $coupons ) : ?>
					<button type="button" class="dpn-coupon-open" data-dpn-coupon-open>
						<span class="dpn-coupon-icon" aria-hidden="true"><?php echo self::icon( 'ticket' ); ?></span>
						<span><?php esc_html_e( 'Vous avez un code promo ?', 'delicat-builder-v9' ); ?></span>
						<strong><?php esc_html_e( 'Saisir le code', 'delicat-builder-v9' ); ?></strong>
					</button>
				<?php endif; ?>
			</article>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Heading around the rendered classic checkout shortcode. Filtering after
	 * do_shortcode also covers Woo's login-required early return, where the
	 * woocommerce_before_checkout_form hook is intentionally never fired.
	 */
	public static function classic_checkout_content( $content ) {
		self::ensure_context();
		if (
			! self::$checkout_shell
			|| self::$checkout_block
			|| self::$checkout_heading_rendered
			|| ! is_string( $content )
			|| ( function_exists( 'is_main_query' ) && ! is_main_query() )
			|| ( function_exists( 'in_the_loop' ) && ! in_the_loop() )
		) {
			return $content;
		}
		self::$checkout_heading_rendered = true;
		/*
		 * RC27: a signed-out visitor cannot check out on this wallet-first store.
		 * WooCommerce's guest output (its "must be logged in" line) and every
		 * third-party prompt hooked into the checkout form are dropped; the one
		 * sign-in surface is Delicat Identity's own modal.
		 */
		if ( self::guest_gate_active() ) {
			return self::guest_gate_markup();
		}
		return self::heading_markup( 'checkout' ) . $content;
	}

	/** Add the same presentation heading without altering a Woo Cart Block. */
	public static function render_cart_block( $content, $block ) {
		unset( $block );
		self::ensure_context();
		if ( ! self::$cart_block || self::$cart_heading_rendered || ! is_string( $content ) ) {
			return $content;
		}
		self::$cart_heading_rendered = true;
		return self::heading_markup( 'cart' ) . $content;
	}

	/** Add the same presentation heading without altering a Woo Checkout Block. */
	public static function render_checkout_block( $content, $block ) {
		unset( $block );
		self::ensure_context();
		if ( ! self::$checkout_shell || ! self::$checkout_block || self::$checkout_heading_rendered || ! is_string( $content ) ) {
			return $content;
		}
		self::$checkout_heading_rendered = true;
		return self::heading_markup( 'checkout' ) . $content;
	}

	/** Digital orders need contact data, not a fabricated shipping address. */
	private static function minimal_checkout_active(): bool {
		if ( ! self::checkout_contract_active() || ! function_exists( 'WC' ) ) {
			return false;
		}

		$woocommerce = WC();
		$cart        = is_object( $woocommerce ) ? $woocommerce->cart : null;
		return is_object( $cart )
			&& is_callable( array( $cart, 'needs_shipping' ) )
			&& ! $cart->needs_shipping();
	}

	/**
	 * Keep Woo's native field objects, posted names and validation pipeline, but
	 * expose only the three contact fields required by this digital storefront.
	 * If a physical product ever enters the cart, Woo's full address form is
	 * preserved automatically so fulfillment data cannot be lost.
	 */
	public static function checkout_fields( $fields ) {
		self::ensure_context();
		if ( ! self::minimal_checkout_active() || ! is_array( $fields ) || empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			return $fields;
		}

		$billing = $fields['billing'];
		$minimal = array();

		if ( isset( $billing['billing_email'] ) && is_array( $billing['billing_email'] ) ) {
			$minimal['billing_email']                = $billing['billing_email'];
			$minimal['billing_email']['label']       = __( 'Adresse e-mail', 'delicat-builder-v9' );
			$minimal['billing_email']['description'] = __( 'Le reçu et le code du produit sont envoyés à cette adresse.', 'delicat-builder-v9' );
			$minimal['billing_email']['required']    = true;
			$minimal['billing_email']['priority']    = 10;
			$minimal['billing_email']['class']       = array( 'form-row-wide' );
		}

		if ( isset( $billing['billing_first_name'] ) && is_array( $billing['billing_first_name'] ) ) {
			$minimal['billing_first_name']                 = $billing['billing_first_name'];
			$minimal['billing_first_name']['label']        = __( 'Nom complet', 'delicat-builder-v9' );
			$minimal['billing_first_name']['required']     = true;
			$minimal['billing_first_name']['priority']     = 20;
			$minimal['billing_first_name']['class']        = array( 'form-row-wide' );
			$minimal['billing_first_name']['autocomplete'] = 'name';
		}

		if ( isset( $billing['billing_phone'] ) && is_array( $billing['billing_phone'] ) ) {
			$minimal['billing_phone']                = $billing['billing_phone'];
			$minimal['billing_phone']['label']       = __( 'Numéro WhatsApp', 'delicat-builder-v9' );
			$minimal['billing_phone']['placeholder'] = __( '+509 XXXX XXXX', 'delicat-builder-v9' );
			$minimal['billing_phone']['description'] = __( 'Pour vous joindre rapidement en cas de souci sur la livraison.', 'delicat-builder-v9' );
			$minimal['billing_phone']['required']    = false;
			$minimal['billing_phone']['priority']    = 30;
			$minimal['billing_phone']['class']       = array( 'form-row-wide' );
		}

		$fields['billing'] = $minimal;
		foreach ( array( 'shipping', 'order', 'account' ) as $group ) {
			if ( isset( $fields[ $group ] ) ) {
				$fields[ $group ] = array();
			}
		}

		return $fields;
	}

	/** Add the reference billing divider without inventing another form field. */
	public static function billing_subheading( $field, $key, $args, $value ) {
		unset( $args, $value );
		if ( 'billing_first_name' !== $key || ! self::minimal_checkout_active() || ! is_string( $field ) ) {
			return $field;
		}

		return '<h3 class="dpn-billing-subheading">'
			. esc_html__( 'Détails de facturation', 'delicat-builder-v9' )
			. '</h3>' . $field;
	}

	/** Keep Woo order notes for shippable carts; omit them for the focused digital form. */
	public static function order_notes_enabled( $enabled ): bool {
		return self::minimal_checkout_active() ? false : (bool) $enabled;
	}

	private static function is_wallet_gateway( $gateway_id ): bool {
		$gateway_id = strtolower( (string) $gateway_id );
		return '' !== $gateway_id && false !== strpos( $gateway_id, 'wallet' );
	}

	/** Rename only the presentation label; the gateway ID and class stay unchanged. */
	public static function gateway_title( $title, $gateway_id ) {
		if ( ! self::checkout_contract_active() || ! self::is_wallet_gateway( $gateway_id ) || ! is_string( $title ) ) {
			return $title;
		}

		return str_ireplace(
			array( 'Wallet payment', 'TeraWallet', 'Tera Wallet', 'Current Balance' ),
			array( __( 'Delicat Wallet', 'delicat-builder-v9' ), __( 'Delicat Wallet', 'delicat-builder-v9' ), __( 'Delicat Wallet', 'delicat-builder-v9' ), __( 'Solde disponible', 'delicat-builder-v9' ) ),
			$title
		);
	}

	/** Localize wallet helper copy without touching balance HTML or gateway data. */
	public static function gateway_description( $description, $gateway_id ) {
		if ( ! self::checkout_contract_active() || ! self::is_wallet_gateway( $gateway_id ) || ! is_string( $description ) ) {
			return $description;
		}

		return str_ireplace(
			array( 'Wallet payment', 'TeraWallet', 'Tera Wallet', 'Current Balance' ),
			array( __( 'Delicat Wallet', 'delicat-builder-v9' ), __( 'Delicat Wallet', 'delicat-builder-v9' ), __( 'Delicat Wallet', 'delicat-builder-v9' ), __( 'Solde disponible', 'delicat-builder-v9' ) ),
			$description
		);
	}

	/** Use Woo's official placeholder so its privacy link remains authoritative. */
	public static function privacy_policy_text( $text, $type = '' ) {
		if ( ! self::checkout_contract_active() || 'checkout' !== (string) $type ) {
			return $text;
		}

		return __( 'Vos données personnelles seront utilisées pour traiter votre commande, vous accompagner sur ce site et selon notre [privacy_policy].', 'delicat-builder-v9' );
	}

	/** Localize the label only; Woo keeps the checkbox name and validation. */
	public static function terms_checkbox_text( $text ) {
		if ( ! self::checkout_contract_active() ) {
			return $text;
		}

		return __( 'J’ai lu et j’accepte les [terms]', 'delicat-builder-v9' );
	}

	/** Semantic card heading before Woo's untouched customer-details fields. */
	public static function checkout_contact_heading(): void {
		self::ensure_context();
		if ( ! self::$checkout_shell || self::$checkout_block ) {
			return;
		}
		?>
		<h2 class="dpn-checkout-section-heading dpn-contact-heading">
			<span aria-hidden="true"><?php echo self::icon( 'user' ); ?></span>
			<?php esc_html_e( 'Coordonnées', 'delicat-builder-v9' ); ?>
		</h2>
		<?php
	}

	/**
	 * A visual mobile control only. It has no form, endpoint, nonce or payment
	 * code; JavaScript reveals it only after finding Woo's real place-order
	 * button, and delegates the click to that button.
	 */
	public static function checkout_dock(): void {
		self::ensure_context();
		if ( ! self::$checkout_shell || self::guest_gate_active() ) {
			return;
		}

		$total = '';
		if ( function_exists( 'WC' ) && WC()->cart && is_callable( array( WC()->cart, 'get_total' ) ) ) {
			$total = (string) WC()->cart->get_total();
		}
		?>
		<aside class="dpn-checkout-dock" data-dpn-checkout-dock hidden aria-label="<?php esc_attr_e( 'Finaliser la commande', 'delicat-builder-v9' ); ?>">
			<div class="dpn-checkout-dock-main">
				<div class="dpn-checkout-dock-total">
					<span><?php esc_html_e( 'Total à payer', 'delicat-builder-v9' ); ?></span>
					<strong data-dpn-checkout-total><?php echo wp_kses_post( $total ); ?></strong>
				</div>
				<button type="button" class="dpn-checkout-dock-submit" data-dpn-checkout-submit disabled>
					<span><?php esc_html_e( 'Passer la commande', 'delicat-builder-v9' ); ?></span>
					<?php echo self::icon( 'arrow' ); ?>
				</button>
			</div>
			<div class="dpn-checkout-dock-trust" id="dpn-checkout-trust">
				<span><?php echo self::icon( 'lock' ); ?><?php esc_html_e( 'Paiement sécurisé', 'delicat-builder-v9' ); ?></span>
				<span><?php echo self::icon( 'bolt' ); ?><?php esc_html_e( 'Délai selon le produit', 'delicat-builder-v9' ); ?></span>
			</div>
		</aside>
		<script id="dpn-checkout-dock-fallback">(function(){var tries=0;var d=document.querySelector('[data-dpn-checkout-dock]');if(!d){return}var b=d.querySelector('[data-dpn-checkout-submit]');var m=d.querySelector('[data-dpn-checkout-total]');function po(){return document.querySelector('#place_order,.wc-block-components-checkout-place-order-button')}function tot(){var n=document.querySelectorAll('#order_review tr.order-total .woocommerce-Price-amount,.wc-block-components-totals-footer-item__value');return n.length?n[n.length-1]:null}function sync(){var a=po();var v=tot();if(m&&v){m.innerHTML=v.innerHTML}if(!a){if(tries++<24){window.setTimeout(sync,500)}return}d.hidden=false;if(document.body.className.indexOf('dpn-checkout-dock-ready')<0){document.body.className+=' dpn-checkout-dock-ready'}if(b){b.disabled=!!a.disabled||a.getAttribute('aria-disabled')==='true';if(!b.__dpnBound){b.__dpnBound=1;b.addEventListener('click',function(){var r=po();if(r){r.click()}})}}window.setTimeout(sync,1500)}window.setTimeout(function(){if(document.body.className.indexOf('dpn-checkout-dock-ready')>=0){return}sync()},1500)})();</script>
		<?php
	}

	/* ---------- Template helpers (called from templates/purchase files) ---------- */

	public static function icon( string $name ): string {
		$icons = array(
			'back'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg>',
			'cart'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 4h2l2.2 10.1a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 1.9-1.4L21 7H6.1M9.5 20a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm9 0a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/></svg>',
			'check'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 12 4 4 8-9"/></svg>',
			'user'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4.5 21v-2.5a5.5 5.5 0 0 1 5.5-5.5h4a5.5 5.5 0 0 1 5.5 5.5V21"/></svg>',
			'ticket' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 7h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4z"/><path d="M8 7v12"/></svg>',
			'trash'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3m3 0-1 13H7L6 7m4 4v5m4-5v5"/></svg>',
			'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l7 3v5c0 4.7-3 8.6-7 10-4-1.4-7-5.3-7-10V6z"/></svg>',
			'lock'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
			'bolt'   => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
			'arrow'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>',
		);
		$icon = $icons[ $name ] ?? '';
		if ( '' === $icon ) {
			return '';
		}

		/*
		 * RC89: intrinsic SVG dimensions are a final safety net when a cache/
		 * optimizer serves checkout HTML before its external CSS is available.
		 * Component CSS may still scale icons down, but they can never expand to
		 * the viewport-sized fallback seen on tablet/desktop checkout.
		 */
		$class = 'dpn-icon dpn-icon-' . sanitize_html_class( $name );
		return str_replace( '<svg ', '<svg class="' . esc_attr( $class ) . '" width="24" height="24" ', $icon );
	}

	/** First non-default product category, uppercased by CSS. */
	public static function item_badge( array $cart_item ): string {
		$product = $cart_item['data'] ?? null;
		if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
			return '';
		}

		$product_id = absint( $product->get_id() );
		if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) && is_callable( array( $product, 'get_parent_id' ) ) ) {
			$product_id = absint( $product->get_parent_id() );
		}

		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! is_array( $terms ) ) {
			return '';
		}
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && 'uncategorized' !== $term->slug ) {
				return $term->name;
			}
		}
		return '';
	}

	public static function shop_url(): string {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		return ( is_string( $shop ) && '' !== $shop ) ? $shop : home_url( '/shop/' );
	}
}
