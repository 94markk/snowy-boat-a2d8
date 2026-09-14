<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Acheter maintenant" opens the checkout in a sheet instead of a page.
 *
 * =============================================================================
 * WHAT THIS IS, AND WHAT IT IS NOT
 * =============================================================================
 * It is NOT a checkout. It does not create orders, take payments, hold locks,
 * validate anything, or know what a gateway is. It moves WooCommerce's own
 * checkout form into a panel on the product page and then gets out of the way.
 *
 * Concretely, when the customer taps "Acheter maintenant":
 *
 *   1. The browser sends THE SAME POST the button already sends - the whole
 *      form.cart, to WooCommerce's own add-to-cart handler, with every field in
 *      it including the supplier Player ID that modules/product-fields captures
 *      on Woo's own hooks. Not a subset, not a reconstruction: the same request,
 *      by fetch instead of by navigating.
 *
 *   2. WooCommerce answers with the checkout page, because the existing
 *      buy-now redirect already sends it there. The sheet lifts form.checkout
 *      out of that response and puts it on screen.
 *
 *   3. WooCommerce's own checkout.js binds to that form, exactly as it does on
 *      the checkout page, and from then on owns everything: the nonce, the
 *      field validation, the AJAX submit to wc-ajax=checkout, the gateway call,
 *      the order, the redirect.
 *
 * So the form is Woo's, the script is Woo's, the endpoint is Woo's, the nonce is
 * Woo's, and the money is Woo's. This file contributes markup and a stylesheet.
 *
 * =============================================================================
 * WHY IT IS BUILT THIS WAY AND NOT THE OTHER WAY
 * =============================================================================
 * V9 had an express checkout once. It posted to a bespoke endpoint that called
 * WooCommerce's checkout processor directly, behind a signed intent, a status
 * poller, and a durable per-customer lock kept in wp_options. The lock outlived
 * a failed payment: a customer whose wallet came up short was left unable to
 * check out AT ALL until an administrator released them by hand from an admin
 * screen. That module is gone and is not coming back.
 *
 * The difference is not carefulness, it is structure. There is no endpoint here
 * to get wrong, no intent to forge, no lock to leak, and no state that outlives
 * the request - because nothing of ours is in the path between the customer and
 * their order.
 *
 * =============================================================================
 * EVERY FAILURE LANDS ON THE CHECKOUT PAGE
 * =============================================================================
 * The button remains an ordinary submit inside form.cart. The sheet intercepts
 * it; if anything at all goes wrong - the fetch fails, the response has no
 * checkout form, jQuery is absent, WooCommerce's script did not load, the
 * customer has JavaScript off - the interception is abandoned and the form
 * submits normally. The customer lands on the real checkout page, which is
 * where they would have landed before this file existed.
 *
 * There is no state in which the sheet failing prevents a purchase.
 */
final class Delicat_Builder_V9_Checkout_Sheet {

	public const HANDLE = 'delicat-builder-v9-checkout-sheet';

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 25 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 30 );
	}

	/**
	 * Is the sheet wanted on this request?
	 *
	 * Product pages only, WooCommerce present, and the merchant's existing
	 * "Commande express" switch on - the same setting and the same per-product
	 * override that used to drive the retired module, so nothing has to be
	 * reconfigured.
	 */
	public static function active(): bool {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) ) && Delicat_Builder_V9_Core::is_safe_mode() ) {
			return false;
		}

		if ( ! class_exists( 'Delicat_Builder_V9_Native_Product', false ) ) {
			return false;
		}

		$id = absint( get_queried_object_id() );
		if ( $id <= 0 ) {
			return false;
		}

		$enabled = is_callable( array( 'Delicat_Builder_V9_Native_Product', 'express_enabled_for' ) )
			? (bool) Delicat_Builder_V9_Native_Product::express_enabled_for( $id )
			: false;

		/** @param bool $enabled */
		return (bool) apply_filters( 'delicat_builder_v9_checkout_sheet', $enabled, $id );
	}

	public static function enqueue(): void {
		if ( ! self::active() ) {
			return;
		}

		/*
		 * WooCommerce's own checkout scripts, on a product page.
		 *
		 * These are what will drive the form once it is in the sheet. Enqueuing
		 * them by handle means WooCommerce localises its own parameters - the
		 * AJAX URL, its nonces, the i18n - exactly as it does on the checkout
		 * page. Nothing here reconstructs any of that, which is the whole point:
		 * a checkout nonce this file minted would be a checkout nonce this file
		 * could get wrong.
		 */
		foreach ( array( 'wc-checkout', 'wc-country-select', 'wc-address-i18n' ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}

		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/checkout-sheet.css';
		if ( is_file( $css ) ) {
			wp_enqueue_style( self::HANDLE, DELICAT_BUILDER_V9_URL . 'assets/css/checkout-sheet.css', array(), DELICAT_BUILDER_V9_VERSION );
		}

		$js = DELICAT_BUILDER_V9_DIR . 'assets/js/checkout-sheet.js';
		if ( ! is_file( $js ) ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			DELICAT_BUILDER_V9_URL . 'assets/js/checkout-sheet.js',
			array( 'jquery' ),
			DELICAT_BUILDER_V9_VERSION,
			array( 'in_footer' => true, 'strategy' => 'defer' )
		);

		wp_add_inline_script( self::HANDLE, 'window.DelicatCheckoutSheet=' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		$config = array(
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : '',
			'walletUrl'   => self::wallet_url(),

			/*
			 * How to recognise "you do not have enough money" in a decline.
			 *
			 * WooCommerce's message is always shown, verbatim, whatever it says
			 * - it is the gateway's own words and this file has no business
			 * rewriting them. These patterns only decide whether to ALSO offer a
			 * button to the top-up page, which is the difference between a
			 * customer who leaves and a customer who tops up.
			 *
			 * Matching on text is inherently approximate, so the failure mode is
			 * chosen deliberately: a miss shows the ordinary decline, which is
			 * correct but less helpful. It can never hide the real message or
			 * claim a payment succeeded.
			 */
			'lowFunds'    => array(
				'insufficient', 'insufficient balance', 'not enough',
				'solde', 'insuffisant', 'insuffisante', 'fonds',
				'balance is low', 'low balance', 'recharge',
			),
			'i18n'        => array(
				'title'        => __( 'Finaliser la commande', 'delicat-builder-v9' ),
				'loading'      => __( 'Préparation de votre commande…', 'delicat-builder-v9' ),
				'close'        => __( 'Fermer', 'delicat-builder-v9' ),
				'lowTitle'     => __( 'Solde insuffisant', 'delicat-builder-v9' ),
				'lowBody'      => __( 'Votre solde ne couvre pas cette commande. Rechargez votre compte, puis revenez finaliser votre achat — votre panier est conservé.', 'delicat-builder-v9' ),
				'lowAction'    => __( 'Recharger mon compte', 'delicat-builder-v9' ),
				'lowSecondary' => __( 'Retour', 'delicat-builder-v9' ),
			),
		);

		/** @param array<string,mixed> $config */
		return (array) apply_filters( 'delicat_builder_v9_checkout_sheet_config', $config );
	}

	/**
	 * Where "Recharger mon compte" goes.
	 *
	 * Resolved from the site's own pages rather than guessed, so a store whose
	 * wallet lives at a translated slug still gets a working button. An
	 * unresolvable wallet means no button rather than a button to a 404.
	 */
	private static function wallet_url(): string {
		foreach ( array( 'my-wallet', 'wallet', 'mon-portefeuille', 'portefeuille', 'recharger-mon-compte' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post ) {
				return (string) get_permalink( $page );
			}
		}

		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( 'woo-wallet' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		/** @param string $url */
		return (string) apply_filters( 'delicat_builder_v9_wallet_url', '' );
	}

	/**
	 * The empty sheet.
	 *
	 * A real <dialog>, so focus containment, Escape, the inertness of the page
	 * behind it and the backdrop are the browser's, not three hundred lines of
	 * ours. It contains nothing until the customer asks for it: no checkout
	 * markup is rendered into a product page, which matters because a product
	 * page is publicly cached and a checkout form carries a per-customer nonce.
	 */
	public static function render(): void {
		if ( ! self::active() ) {
			return;
		}

		$i18n = self::config()['i18n'];
		?>
		<dialog class="dcs" id="dcs-sheet" aria-label="<?php echo esc_attr( $i18n['title'] ); ?>">
			<div class="dcs__grip" aria-hidden="true"></div>

			<div class="dcs__head">
				<h2 class="dcs__title"><?php echo esc_html( $i18n['title'] ); ?></h2>
				<button type="button" class="dcs__close" data-dcs-close aria-label="<?php echo esc_attr( $i18n['close'] ); ?>">
					<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
				</button>
			</div>

			<div class="dcs__body" data-dcs-body>
				<div class="dcs__loading" data-dcs-loading>
					<span class="dcs__spinner" aria-hidden="true"></span>
					<p><?php echo esc_html( $i18n['loading'] ); ?></p>
				</div>
			</div>
		</dialog>
		<?php
	}
}
