<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Express review sheet for opted-in products (RC24 transport).
 *
 * "Acheter maintenant" posts the product form through WooCommerce's classic
 * add-to-cart handler (fetch). WooCommerce answers with JSON directly —
 * accepted or its own error notices — so no redirect can be lost on the way.
 * The sheet then loads WooCommerce's real checkout form from
 * `wc-ajax=delicat_express_form` (fields, gateway, terms, process-checkout
 * nonce, all rendered for the current cart) and shows it as a review: order
 * lines with their product fields, total, wallet balance, one "Payer" button.
 * Wallet submissions pass through the guarded express transport to WC_Checkout.
 * Other gateways keep full checkout. WooCommerce owns orders and charges.
 *
 * Nothing sensitive is rendered on the product page itself (the form arrives
 * on demand), so product pages stay cacheable exactly as before.
 * Signed-in clients only; guests keep the checkout page.
 */
final class Delicat_Builder_V9_Express_Checkout {
	private static bool $active     = false;
	private static int  $product_id = 0;

	public static function boot_for_product( int $product_id ): void {
		if ( self::$active || $product_id <= 0 || ! is_user_logged_in() || ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Purchase_Native', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-purchase-native.php' );
		}
		if (
			! class_exists( 'Delicat_Builder_V9_Purchase_Native', false )
			|| ! is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'express_supported' ) )
			|| ! Delicat_Builder_V9_Purchase_Native::express_supported()
		) {
			self::record( $product_id, 'blocked', 'Purchase Studio ou « Woo-native checkout shell » est désactivé.' );
			return;
		}
		try {
			Delicat_Builder_V9_Purchase_Native::boot();
			Delicat_Builder_V9_Purchase_Native::mark_express();
		} catch ( Throwable $error ) {
			self::record( $product_id, 'blocked', 'Erreur au démarrage : ' . $error->getMessage() );
			return;
		}
		self::$active     = true;
		self::$product_id = $product_id;

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 45 );
		add_action( 'wp_footer', array( __CLASS__, 'sheet' ), 97 );
	}

	public static function is_active(): bool {
		return self::$active;
	}

	private static function record( int $id, string $state, string $detail = '' ): void {
		if ( is_callable( array( 'Delicat_Builder_V9_Native_Product', 'record_express' ) ) ) {
			Delicat_Builder_V9_Native_Product::record_express( $id, $state, $detail );
		}
	}

	public static function body_class( $classes ): array {
		$classes   = is_array( $classes ) ? $classes : array();
		/* Not 'dnp-express' — that is the sheet container's own class. */
		$classes[] = 'dnp-express-enabled';
		return array_values( array_unique( $classes ) );
	}

	private static function config(): array {
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( self::$product_id ) : null;
		$terms_url  = '';
		$terms_page = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
		if ( $terms_page > 0 ) {
			$terms_url = (string) get_permalink( $terms_page );
		}
		$debug = isset( $_GET['dnp_express_debug'] ) && ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin diagnostic flag.
		return array(
			'productId'    => self::$product_id,
			'addNonce'     => wp_create_nonce( 'delicat_express_add' ),
			'ordersUrl'    => wc_get_account_endpoint_url( 'orders' ),
			'cartUrl'      => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
			'productName'  => $product instanceof WC_Product ? $product->get_name() : '',
			'formUrl'      => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'delicat_express_form' ) : '',
			'statusUrl'    => WC_AJAX::get_endpoint( 'delicat_express_status' ),
			'checkoutUrl'  => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'delicat_express_pay' ) : '',
			'fullCheckout' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' ),
			'walletUrl'    => is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'wallet_url' ) ) ? Delicat_Builder_V9_Purchase_Native::wallet_url() : '',
			'termsUrl'     => $terms_url,
			'debug'        => $debug ? 1 : 0,
			'i18n'         => array(
				'adding'       => __( 'Ajout au panier…', 'delicat-builder-v9' ),
				'loading'      => __( 'Préparation de votre commande…', 'delicat-builder-v9' ),
				'pay'          => __( 'Payer maintenant', 'delicat-builder-v9' ),
				'paying'       => __( 'Paiement en cours…', 'delicat-builder-v9' ),
				'recharge'     => __( 'Recharger mon compte', 'delicat-builder-v9' ),
				'insufficient' => __( 'Le solde de votre compte est insuffisant, veuillez recharger votre compte.', 'delicat-builder-v9' ),
				'noGateway'    => __( 'Aucun mode de paiement disponible pour cette commande.', 'delicat-builder-v9' ),
				'failed'       => __( 'Le paiement n’a pas pu être confirmé. Vérifiez les informations ci-dessous.', 'delicat-builder-v9' ),
				'network'      => __( 'Connexion interrompue. Réessayez ou ouvrez le paiement complet.', 'delicat-builder-v9' ),
				'refused'      => __( 'Vérifiez votre sélection avant de continuer.', 'delicat-builder-v9' ),
				'quantity'     => __( 'Quantité', 'delicat-builder-v9' ),
				'product'      => __( 'Produit', 'delicat-builder-v9' ),
				'option'       => __( 'Option', 'delicat-builder-v9' ),
				'subtotal'     => __( 'Sous-total', 'delicat-builder-v9' ),
				'total'        => __( 'Total', 'delicat-builder-v9' ),
				'balance'      => __( 'Solde wallet', 'delicat-builder-v9' ),
				'after'        => __( 'Après paiement', 'delicat-builder-v9' ),
				'payment'      => __( 'Paiement', 'delicat-builder-v9' ),
				'details'      => __( 'Vos coordonnées', 'delicat-builder-v9' ),
				'fullCheckout' => __( 'Ouvrir le paiement complet', 'delicat-builder-v9' ),
				'termsNote'    => __( 'En payant, vous acceptez les', 'delicat-builder-v9' ),
				'termsLabel'   => __( 'conditions d’utilisation', 'delicat-builder-v9' ),
			),
		);
	}

	public static function assets(): void {
		if ( ! self::$active ) {
			return;
		}
		wp_enqueue_style( 'delicat-builder-v9-express', DELICAT_BUILDER_V9_URL . 'assets/css/express-checkout.css', array(), DELICAT_BUILDER_V9_VERSION );
		if ( is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'express_presentation_css' ) ) ) {
			wp_add_inline_style( 'delicat-builder-v9-express', Delicat_Builder_V9_Purchase_Native::express_presentation_css() );
		}
		wp_enqueue_script( 'delicat-builder-v9-express', DELICAT_BUILDER_V9_URL . 'assets/js/express-checkout.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		wp_add_inline_script( 'delicat-builder-v9-express', 'window.DelicatExpress=' . wp_json_encode( self::config() ) . ';', 'before' );
		if ( is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'enqueue_wallet_guard' ) ) ) {
			Delicat_Builder_V9_Purchase_Native::enqueue_wallet_guard();
		}
	}

	/** Static shell only; WooCommerce's form arrives on demand after the item is in the cart. */
	public static function sheet(): void {
		if ( ! self::$active ) {
			return;
		}
		self::record( self::$product_id, 'rendered', '' );
		?>
		<div class="dnp-express" data-dnp-express data-config="<?php echo esc_attr( wp_json_encode( self::config() ) ); ?>" role="dialog" aria-modal="true" aria-labelledby="dnp-express-title" hidden>
			<div class="dnp-express-backdrop" data-dnp-express-close></div>
			<div class="dnp-express-sheet" tabindex="-1" data-dnp-express-sheet>
				<span class="dnp-express-grip" aria-hidden="true"></span>
				<button type="button" class="dnp-express-close" data-dnp-express-close aria-label="<?php esc_attr_e( 'Fermer', 'delicat-builder-v9' ); ?>"><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
				<div class="dnp-express-scroll" data-dnp-express-scroll>
					<div class="dnp-express-hero">
						<span class="dnp-express-badge" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
						<h2 id="dnp-express-title"><?php esc_html_e( 'Vérifiez votre commande', 'delicat-builder-v9' ); ?></h2>
						<p><?php esc_html_e( 'Vérifiez tous les articles de votre panier.', 'delicat-builder-v9' ); ?></p>
					</div>
					<div class="dnp-express-notice" data-dnp-express-notice hidden></div>
					<div class="dnp-express-summary" data-dnp-express-summary aria-live="polite">
						<div class="dnp-express-skeleton" aria-hidden="true"><span></span><span></span><span></span><span class="is-total"></span></div>
					</div>
					<div class="dnp-express-details" data-dnp-express-details hidden></div>
				</div>
				<div class="dnp-express-footer">
					<button type="button" class="dnp-express-pay" data-dnp-express-pay disabled><span data-dnp-express-pay-label><?php esc_html_e( 'Payer maintenant', 'delicat-builder-v9' ); ?></span><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
					<p class="dnp-express-terms" data-dnp-express-terms hidden></p>
					<p class="dnp-express-foot"><span><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><?php esc_html_e( 'Paiement sécurisé WooCommerce', 'delicat-builder-v9' ); ?></span><span><svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 2 4.5 13.2h6.2L10 22l9-12h-6.3L13 2Z"/></svg><?php esc_html_e( 'Délai selon le produit', 'delicat-builder-v9' ); ?></span></p>
				</div>
				<div class="dnp-express-woo" data-dnp-express-woo hidden aria-hidden="true"></div>
			</div>
		</div>
		<?php
	}
}
