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

	/**
	 * The header the sheet's own fetch sends, and nothing else does.
	 *
	 * The summary and wallet cards below are rendered onto the checkout page
	 * ONLY for a request carrying this. An ordinary visit to the checkout page
	 * is byte-for-byte what it was, which matters because those cards are the
	 * one part of this feature that costs a query.
	 */
	public const REQUEST_HEADER = 'HTTP_X_DELICAT_SHEET';

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 25 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 30 );

		if ( self::is_sheet_request() ) {
			/* Before the form, so it lands above it in the response and the
			 * sheet can lift the two out together. */
			add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_summary' ), 5 );

			/* WooCommerce's own button, relabelled with the total the customer
			 * is about to pay. Through Woo's filter, so it is still Woo's
			 * button doing Woo's submit - only the words change. */
			add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ) );
		}
	}

	public static function is_sheet_request(): bool {
		return ! empty( $_SERVER[ self::REQUEST_HEADER ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation switch only; changes no state and gates no capability.
	}

	/** @param string $text */
	public static function order_button_text( $text ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return (string) $text;
		}

		$total = wp_strip_all_tags( (string) WC()->cart->get_total() );
		return '' === $total
			? (string) $text
			: sprintf(
				/* translators: %s: the order total. */
				__( 'Payer maintenant %s', 'delicat-builder-v9' ),
				$total
			);
	}

	/* =====================================================================
	 * The summary
	 * =====================================================================
	 * Everything below reads WooCommerce and the wallet plugin. Not one figure
	 * is computed here: the line items are WC()->cart's, the formatted option
	 * rows are wc_get_formatted_cart_item_data()'s - the same pairs WooCommerce
	 * prints on its own cart page, so a custom field added by another plugin
	 * appears here without this file knowing it exists - the total is
	 * WC()->cart->get_total(), and the balance is the wallet plugin's own.
	 * ===================================================================== */

	/**
	 * The label the pay button wears while WooCommerce is taking the payment.
	 *
	 * Handed to CSS as a custom property because the swap itself is a
	 * stylesheet's job - it keys off the .processing class WooCommerce already
	 * puts on the form - but the words have to come through translation like
	 * every other string here.
	 */
	public static function paying_label_style(): string {
		/* A quote or a backslash inside the value would end the CSS string
		   early, so they go before it is ever put in one. Escaping for the
		   attribute happens once, where it is printed. */
		$label = str_replace( array( '"', '\\' ), '', (string) __( 'Paiement en cours…', 'delicat-builder-v9' ) );
		return '--dcs-paying:"' . $label . '"';
	}

	/**
	 * Does the wallet fall short of this order?
	 *
	 * Answered here, on the server, from the wallet plugin's own balance and
	 * WooCommerce's own total - the same two figures the card below prints. The
	 * browser is told the answer, never the arithmetic.
	 *
	 * @return bool True only when a balance is readable AND it will not cover
	 *              the total. An unreadable balance is not a shortfall.
	 */
	private static function wallet_is_short(): bool {
		if ( ! is_user_logged_in() || ! function_exists( 'woo_wallet' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		try {
			$balance = (float) woo_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' );
		} catch ( Throwable $error ) {
			unset( $error );
			return false;
		}

		return $balance < (float) WC()->cart->get_total( 'edit' );
	}

	public static function render_summary(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		/*
		 * The shortfall is announced with the summary rather than waited for.
		 *
		 * WooCommerce will say "solde insuffisant" too, but only after the
		 * customer has filled the form in and pressed pay. The balance and the
		 * total are both known the moment this renders, so the customer is told
		 * now, with the way out - and can still pick another payment method,
		 * because nothing here refuses anything. WooCommerce remains the only
		 * thing that decides whether a payment may proceed.
		 */
		printf(
			'<div class="dcs-summary" data-dcs-summary%s>',
			self::wallet_is_short() ? ' data-dcs-short="1"' : ''
		);
		self::render_order_card();
		self::render_wallet_card();
		echo '</div>';
	}

	private static function render_order_card(): void {
		echo '<div class="dcs-card dcs-card--order">';

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			self::row( __( 'Produit', 'delicat-builder-v9' ), esc_html( $product->get_name() ), 'strong' );

			/*
			 * The option rows - "1 mois", the Netflix account email, a Player
			 * ID. This is WooCommerce's own formatter, so whatever another
			 * plugin attached to the line shows up here in the merchant's own
			 * wording, and nothing here has to know what any of it means.
			 */
			$meta = function_exists( 'wc_get_formatted_cart_item_data' )
				? wc_get_formatted_cart_item_data( $item, true )
				: '';

			foreach ( self::parse_item_data( (string) $meta ) as $label => $value ) {
				self::row( $label, esc_html( $value ) );
			}
		}

		printf(
			'<div class="dcs-row dcs-row--total"><span class="dcs-row__label">%1$s</span><span class="dcs-row__value dcs-row__value--strong"><span class="dcs-total">%2$s</span></span></div>',
			esc_html__( 'Total', 'delicat-builder-v9' ),
			wp_kses_post( WC()->cart->get_total() )
		);

		echo '</div>';
	}

	/**
	 * WooCommerce hands back "Label: value" lines. Split them so each becomes a
	 * row rather than one run-on paragraph.
	 *
	 * @return array<string,string>
	 */
	private static function parse_item_data( string $formatted ): array {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $formatted ) ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $label, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			if ( '' !== $label && '' !== $value ) {
				$out[ $label ] = $value;
			}
		}

		return $out;
	}

	/**
	 * Balance, what is left after this order, and which method is selected.
	 *
	 * Omitted entirely when there is no wallet plugin, no signed-in customer or
	 * no readable balance - a card that says nothing is worse than no card, and
	 * a wrong balance on a payment screen is worse than both.
	 */
	private static function render_wallet_card(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'woo_wallet' ) ) {
			return;
		}

		$balance = null;
		try {
			$balance = (float) woo_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' );
		} catch ( Throwable $error ) {
			unset( $error );
			return;
		}

		if ( null === $balance ) {
			return;
		}

		$total = (float) WC()->cart->get_total( 'edit' );
		$after = $balance - $total;

		echo '<div class="dcs-card dcs-card--wallet">';

		self::row( __( 'Solde wallet', 'delicat-builder-v9' ), wp_kses_post( wc_price( $balance ) ), 'strong' );

		printf(
			'<div class="dcs-row"><span class="dcs-row__label">%1$s</span><span class="dcs-row__value dcs-row__value--%2$s">%3$s</span></div>',
			esc_html__( 'Après paiement', 'delicat-builder-v9' ),
			$after < 0 ? 'short' : 'ok',
			wp_kses_post( wc_price( $after ) )
		);

		$gateway = self::selected_gateway_title();
		if ( '' !== $gateway ) {
			printf(
				'<div class="dcs-row"><span class="dcs-row__label">%1$s</span><span class="dcs-row__value dcs-row__value--strong"><span class="dcs-dot" aria-hidden="true"></span>%2$s</span></div>',
				esc_html__( 'Paiement', 'delicat-builder-v9' ),
				esc_html( $gateway )
			);
		}

		echo '</div>';
	}

	private static function selected_gateway_title(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return '';
		}

		$available = WC()->payment_gateways()->get_available_payment_gateways();
		$chosen    = WC()->session ? (string) WC()->session->get( 'chosen_payment_method' ) : '';

		if ( '' !== $chosen && isset( $available[ $chosen ] ) ) {
			return wp_strip_all_tags( (string) $available[ $chosen ]->get_title() );
		}

		$first = is_array( $available ) ? reset( $available ) : null;
		return $first ? wp_strip_all_tags( (string) $first->get_title() ) : '';
	}

	/** @param string $value Already-escaped markup. */
	private static function row( string $label, string $value, string $modifier = '' ): void {
		printf(
			'<div class="dcs-row"><span class="dcs-row__label">%1$s</span><span class="dcs-row__value%2$s">%3$s</span></div>',
			esc_html( $label ),
			'' !== $modifier ? ' dcs-row__value--' . esc_attr( $modifier ) : '',
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by every caller above.
		);
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

		/*
		 * The sheet's own CSS and JS are NOT loaded here.
		 *
		 * Together they are about 44 KB that every visitor to every product page
		 * was paying for, to support one button that most visits never press. On
		 * the connections this shop sells over that is a slower product page for
		 * everybody, bought for the few who buy.
		 *
		 * They load on intent instead - see print_loader() below - which is the
		 * same trade the notifications bell makes. WooCommerce's own checkout
		 * scripts above stay eagerly enqueued: they carry parameters WordPress
		 * prints alongside them, and reconstructing those by hand is exactly the
		 * kind of cleverness that has no place near a payment.
		 */
		self::print_loader();
	}

	/**
	 * Load the sheet when the customer reaches for it, not before.
	 *
	 * Two moments, because they answer different needs. `pointerdown` on the buy
	 * button starts the download while the finger is still on the glass, so by
	 * the time the tap completes the sheet is usually already there. `submit` is
	 * the guarantee: it catches the keyboard, the slow connection where the
	 * download has not arrived yet, and the tap that skipped pointerdown - it
	 * holds the submission, waits, and then re-submits so the loaded script
	 * handles it exactly as if it had been there all along.
	 *
	 * If anything at all goes wrong the submission is released untouched and the
	 * customer lands on the real checkout page, which is where every other
	 * failure in this feature ends too.
	 */
	private static function print_loader(): void {
		if ( ! is_file( DELICAT_BUILDER_V9_DIR . 'assets/js/checkout-sheet.js' ) ) {
			return;
		}

		wp_register_script( self::HANDLE, false, array( 'jquery' ), DELICAT_BUILDER_V9_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, self::loader_script() );
	}

	/**
	 * The loader, as a string, so it can be read and exercised rather than only
	 * printed. The browser suite runs this exact text.
	 */
	public static function loader_script(): string {
		$css = DELICAT_BUILDER_V9_URL . 'assets/css/checkout-sheet.css';
		$js  = DELICAT_BUILDER_V9_URL . 'assets/js/checkout-sheet.js';

		return sprintf(
			'window.DelicatCheckoutSheet=%s;(function(){var c=%s,j=%s,s=0;' .
			/* 0 idle, 1 loading, 2 ready, 3 gave up */
			'function flush(){var q=window.__dcsQ||[];window.__dcsQ=[];for(var i=0;i<q.length;i++){try{q[i]();}catch(e){}}}' .
			'function load(done){if(s===2||s===3){done&&done();return;}if(done){(window.__dcsQ=window.__dcsQ||[]).push(done);}' .
			'if(s===1)return;s=1;' .
			'var l=document.createElement("link");l.rel="stylesheet";l.href=c;document.head.appendChild(l);' .
			'var t=document.createElement("script");t.src=j;' .
			't.onload=function(){s=2;flush();};' .
			/*
			 * The script did not arrive. Whatever is waiting still runs - and
			 * because the state is now 3, the submit listener below stands aside
			 * and the form goes where it always went: the real checkout page. A
			 * customer whose connection dropped the script must never press
			 * "Acheter maintenant" and have nothing happen at all.
			 */
			't.onerror=function(){s=3;flush();};' .
			'document.head.appendChild(t);}' .
			'function isBuy(n){return n&&n.name==="delicat_native_buy_now";}' .
			/* warm it while the finger is still down */
			'document.addEventListener("pointerdown",function(e){var b=e.target&&e.target.closest?e.target.closest("[name=delicat_native_buy_now]"):null;if(b)load(null);},{passive:true,capture:true});' .
			'document.addEventListener("submit",function(e){if(s===2||s===3)return;var b=e.submitter;if(!isBuy(b))return;' .
			'var f=e.target;if(!f||!f.requestSubmit)return;' .
			'e.preventDefault();e.stopImmediatePropagation();' .
			'load(function(){try{f.requestSubmit(b);}catch(err){f.submit();}});},true);}());',
			wp_json_encode( self::config() ),
			wp_json_encode( $css ),
			wp_json_encode( $js )
		);
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
				'title'        => __( 'Vérifiez votre commande', 'delicat-builder-v9' ),
				'subtitle'     => __( 'Vérifiez tous les articles de votre panier.', 'delicat-builder-v9' ),
				'secure'       => __( 'Paiement sécurisé WooCommerce', 'delicat-builder-v9' ),
				'delay'        => __( 'Délai selon le produit', 'delicat-builder-v9' ),
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
		<dialog class="dcs" id="dcs-sheet" aria-label="<?php echo esc_attr( $i18n['title'] ); ?>" style="<?php echo esc_attr( self::paying_label_style() ); ?>">
			<button type="button" class="dcs__close" data-dcs-close aria-label="<?php echo esc_attr( $i18n['close'] ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
			</button>

			<?php // tabindex/autofocus so opening the sheet focuses the panel itself.
			// Without it the dialog focuses the first focusable child - the close
			// button - and every customer opens the sheet to a focus ring on the one
			// control that discards it. ?>
			<div class="dcs__scroll" data-dcs-scroll tabindex="-1" autofocus>
				<div class="dcs__grip" aria-hidden="true"></div>

				<div class="dcs__head">
					<span class="dcs__mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
					</span>
					<h2 class="dcs__title"><?php echo esc_html( $i18n['title'] ); ?></h2>
					<p class="dcs__sub"><?php echo esc_html( $i18n['subtitle'] ); ?></p>
				</div>

				<div class="dcs__body" data-dcs-body>
					<div class="dcs__loading" data-dcs-loading>
						<span class="dcs__spinner" aria-hidden="true"></span>
						<p><?php echo esc_html( $i18n['loading'] ); ?></p>
					</div>
				</div>
			</div>

			<div class="dcs__trust" data-dcs-trust hidden>
				<span><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8.2 10.5V7.6a3.8 3.8 0 0 1 7.6 0v2.9"/></svg><?php echo esc_html( $i18n['secure'] ); ?></span>
				<span><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.2 2.5 5.4 13.1h5.6L10.8 21.5l7.8-10.6H13Z"/></svg><?php echo esc_html( $i18n['delay'] ); ?></span>
			</div>
		</dialog>
		<?php
	}
}
