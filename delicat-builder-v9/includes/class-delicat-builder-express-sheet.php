<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The express review sheet: "Acheter maintenant" without leaving the product.
 *
 * =============================================================================
 * WHAT THIS IS
 * =============================================================================
 * A REVIEW, not a checkout. The customer sees what they are buying, what it
 * costs, what their wallet will be left with, the few fields WooCommerce asks
 * for, and one button. That is the whole surface.
 *
 * It is emphatically NOT the checkout page in a panel. An earlier attempt put
 * the entire WooCommerce checkout document inside an iframe; it worked, and it
 * was wrong, because the point of the express path is to be shorter than the
 * checkout page rather than the same length in a smaller box.
 *
 * =============================================================================
 * WHAT WOOCOMMERCE STILL OWNS - WHICH IS EVERYTHING THAT MATTERS
 * =============================================================================
 * The form in the sheet IS WooCommerce's form, fetched from WooCommerce and
 * moved into the panel intact. Its nonce is WooCommerce's nonce. Its fields are
 * WooCommerce's fields. Its submit is WooCommerce's own AJAX submit, bound by
 * WooCommerce's own checkout script, to WooCommerce's own endpoint.
 *
 * This class never mints a nonce, never reads or writes a price, never creates
 * an order, never touches a gateway, and never decides whether a payment may
 * proceed. It cannot: there is no code here that could.
 *
 * Transport is HTTPS, which is what "encrypted" means for a checkout. Nothing
 * here invents a second layer on top of it, because a hand-rolled one would be
 * worse than the one already there.
 *
 * =============================================================================
 * IT CANNOT COST A SALE
 * =============================================================================
 * Every failure - the script not loading, the fetch failing, the reply not
 * containing a form, a browser without <dialog> - abandons the sheet and lets
 * the button do what it always did: submit, and land on the real checkout page.
 * There is no state in which this failing stops someone buying something.
 *
 * =============================================================================
 * WHY THE NAMES CHANGED
 * =============================================================================
 * This replaces a module called the "checkout sheet", whose CSS class prefix was
 * `dcs`. Every name here is new - the class, both asset files, the `dxs` prefix,
 * the DOM ids, the global - so that a page cached from any earlier version
 * cannot style this sheet with its old stylesheet or drive it with its old
 * script. Old and new can no longer meet.
 */
final class Delicat_Builder_V9_Express_Sheet {

	public const HANDLE = 'delicat-builder-v9-express-sheet';

	/**
	 * The header the sheet's own fetch sends, and nothing else does.
	 *
	 * The summary cards below are rendered onto the checkout page ONLY for a
	 * request carrying this, so an ordinary visit to the checkout page is
	 * byte-for-byte what it was.
	 */
	public const REQUEST_HEADER = 'HTTP_X_DELICAT_EXPRESS';

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 25 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 30 );

		/*
		 * These two fire on the CHECKOUT request the sheet fetches, not on the
		 * product page. The class is loaded on both - see the runtime router -
		 * because the cards are rendered on one and the panel on the other.
		 */
		if ( self::is_sheet_request() ) {
			add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_summary' ), 5 );
			add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ) );
		}
	}

	public static function is_sheet_request(): bool {
		return ! empty( $_SERVER[ self::REQUEST_HEADER ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presentation switch only; changes no state and gates no capability.
	}

	/* =====================================================================
	 * The summary
	 * =====================================================================
	 * Every figure below is read, never computed. The lines are WC()->cart's,
	 * the option rows are wc_get_formatted_cart_item_data()'s - the same pairs
	 * WooCommerce prints on its own cart page, so a field another plugin
	 * attached to the line appears here without this file knowing it exists -
	 * the total is WC()->cart->get_total(), and the balance is the wallet
	 * plugin's own.
	 * ================================================================== */

	public static function render_summary(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}

		printf(
			'<div class="dxs-summary" data-dxs-summary%s>',
			self::wallet_is_short() ? ' data-dxs-short="1"' : ''
		);
		self::render_order_card();
		self::render_wallet_card();
		echo '</div>';
	}

	private static function render_order_card(): void {
		echo '<div class="dxs-card dxs-card--order">';

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			self::row( __( 'Produit', 'delicat-builder-v9' ), esc_html( $product->get_name() ), 'strong' );

			$meta = function_exists( 'wc_get_formatted_cart_item_data' )
				? wc_get_formatted_cart_item_data( $item, true )
				: '';

			foreach ( self::parse_item_data( (string) $meta ) as $label => $value ) {
				self::row( $label, esc_html( $value ) );
			}
		}

		printf(
			'<div class="dxs-row dxs-row--total"><span class="dxs-row__label">%1$s</span><span class="dxs-row__value dxs-row__value--strong"><span class="dxs-total">%2$s</span></span></div>',
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
	 * Does the wallet fall short of this order?
	 *
	 * Answered here, from the wallet plugin's own balance and WooCommerce's own
	 * total - the same two figures the card prints. The browser is handed the
	 * answer, never the arithmetic.
	 *
	 * An unreadable balance is NOT a shortfall: telling someone their balance is
	 * too low when it could not be read is worse than saying nothing.
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

	/**
	 * Balance, what is left after this order, and which method is selected.
	 *
	 * Omitted entirely when there is no wallet plugin, no signed-in customer or
	 * no readable balance. A card that says nothing is worse than no card, and a
	 * wrong balance on a payment screen is worse than both.
	 */
	private static function render_wallet_card(): void {
		if ( ! is_user_logged_in() || ! function_exists( 'woo_wallet' ) ) {
			return;
		}

		try {
			$balance = (float) woo_wallet()->wallet->get_wallet_balance( get_current_user_id(), 'edit' );
		} catch ( Throwable $error ) {
			unset( $error );
			return;
		}

		$after = $balance - (float) WC()->cart->get_total( 'edit' );

		echo '<div class="dxs-card dxs-card--wallet">';

		self::row( __( 'Solde wallet', 'delicat-builder-v9' ), wp_kses_post( wc_price( $balance ) ), 'strong' );

		printf(
			'<div class="dxs-row"><span class="dxs-row__label">%1$s</span><span class="dxs-row__value dxs-row__value--%2$s">%3$s</span></div>',
			esc_html__( 'Après paiement', 'delicat-builder-v9' ),
			$after < 0 ? 'short' : 'ok',
			wp_kses_post( wc_price( $after ) )
		);

		$gateway = self::selected_gateway_title();
		if ( '' !== $gateway ) {
			printf(
				'<div class="dxs-row"><span class="dxs-row__label">%1$s</span><span class="dxs-row__value dxs-row__value--strong"><span class="dxs-dot" aria-hidden="true"></span>%2$s</span></div>',
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
			'<div class="dxs-row"><span class="dxs-row__label">%1$s</span><span class="dxs-row__value%2$s">%3$s</span></div>',
			esc_html( $label ),
			'' !== $modifier ? ' dxs-row__value--' . esc_attr( $modifier ) : '',
			$value // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by every caller above.
		);
	}

	/**
	 * Keep the cards true after WooCommerce recalculates.
	 *
	 * Changing the payment method, or a quantity, makes WooCommerce re-render
	 * its order review and hand back fragments; its own script replaces each
	 * element matching the key. Ours goes along, so the total, the balance and
	 * the selected method on this screen cannot drift away from what is about to
	 * be charged.
	 *
	 * Nothing here decides anything - it is the same render as the first one,
	 * against the cart WooCommerce has just recalculated.
	 *
	 * @param  array<string,string> $fragments
	 * @return array<string,string>
	 */
	public static function summary_fragments( $fragments ): array {
		$fragments = is_array( $fragments ) ? $fragments : array();

		ob_start();
		self::render_summary();
		$markup = (string) ob_get_clean();

		if ( '' !== $markup ) {
			$fragments['.dxs-summary'] = $markup;
		}

		return $fragments;
	}

	/** WooCommerce's own button, relabelled with the total, through Woo's filter. */
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
	 * Where the sheet belongs
	 * ================================================================== */

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
		return (bool) apply_filters( 'delicat_builder_v9_express_sheet', $enabled, $id );
	}

	public static function enqueue(): void {
		if ( ! self::active() ) {
			return;
		}

		/*
		 * WooCommerce's own checkout scripts, on a product page.
		 *
		 * These drive the form once it is in the sheet. Enqueuing them BY HANDLE
		 * means WordPress prints WooCommerce's own localised parameters - the
		 * AJAX url, the nonces, the i18n - exactly as on the checkout page.
		 * Nothing here reconstructs any of that, which is the point: a checkout
		 * nonce this file minted would be one this file could get wrong.
		 */
		foreach ( array( 'wc-checkout', 'wc-country-select', 'wc-address-i18n' ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				wp_enqueue_script( $handle );
			}
		}

		/*
		 * The sheet's own CSS and JS are NOT enqueued here. Together they are
		 * tens of kilobytes on every product page, for a button most visits
		 * never press, on connections where that is the difference between a
		 * fast product page and a slow one. They load on intent instead.
		 */
		self::print_loader();
	}

	private static function print_loader(): void {
		if ( ! is_file( DELICAT_BUILDER_V9_DIR . 'assets/js/express-sheet.js' ) ) {
			return;
		}

		wp_register_script( self::HANDLE, false, array( 'jquery' ), DELICAT_BUILDER_V9_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, self::loader_script() );
	}

	/**
	 * Load the sheet when the customer reaches for it, not before.
	 *
	 * Returned as a string so it can be read and exercised rather than only
	 * printed - the browser suite runs this exact text. A loader standing
	 * between a customer and their purchase is not a thing to test a copy of.
	 *
	 * Three ways in, because they fail differently:
	 *
	 *   pointerdown  starts the download while the finger is still on the glass,
	 *                so the sheet is usually there before the tap completes
	 *   submit       the guarantee - catches the keyboard, the slow connection,
	 *                and any tap that skipped pointerdown; holds the submission,
	 *                waits, then re-submits so the loaded script handles it
	 *   open()       the floating dock, which asks for the sheet by name rather
	 *                than by clicking the button underneath it
	 *
	 * If the script cannot be fetched, everything waiting is released and the
	 * loader stands aside, so the form goes where it always went.
	 */
	public static function loader_script(): string {
		return sprintf(
			'window.DelicatExpressSheet=%1$s;(function(){var c=%2$s,j=%3$s,s=0,Q=[];' .
			/* 0 idle, 1 loading, 2 ready, 3 gave up */
			'function flush(){var q=Q;Q=[];for(var i=0;i<q.length;i++){try{q[i]();}catch(e){}}}' .
			'function load(done){if(s===2||s===3){done&&done();return;}if(done)Q.push(done);if(s===1)return;s=1;' .
			'var l=document.createElement("link");l.rel="stylesheet";l.href=c;document.head.appendChild(l);' .
			'var t=document.createElement("script");t.src=j;' .
			't.onload=function(){s=2;flush();};' .
			't.onerror=function(){s=3;flush();};' .
			'document.head.appendChild(t);}' .
			'window.DelicatExpressSheetLoad=load;' .
			'function isBuy(n){return !!n&&n.name==="delicat_native_buy_now";}' .
			'document.addEventListener("pointerdown",function(e){' .
			'var b=e.target&&e.target.closest?e.target.closest("[name=delicat_native_buy_now],[data-dnp-proxy=buy]"):null;' .
			'if(b)load(null);},{passive:true,capture:true});' .
			'document.addEventListener("submit",function(e){if(s===2||s===3)return;' .
			'var b=e.submitter;if(!isBuy(b))return;var f=e.target;if(!f||!f.requestSubmit)return;' .
			'e.preventDefault();e.stopImmediatePropagation();' .
			'load(function(){try{f.requestSubmit(b);}catch(err){f.submit();}});},true);' .
			/* The floating dock calls this instead of clicking the button it covers. */
			'window.DelicatExpressSheetOpen=function(form,btn){if(!form||!btn)return false;' .
			'load(function(){try{form.requestSubmit(btn);}catch(e){btn.click();}});return true;};}());',
			wp_json_encode( self::config() ),
			wp_json_encode( DELICAT_BUILDER_V9_URL . 'assets/css/express-sheet.css' ),
			wp_json_encode( DELICAT_BUILDER_V9_URL . 'assets/js/express-sheet.js' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function config(): array {
		return array(
			'checkoutUrl' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ),
			'walletUrl'   => self::wallet_url(),
			/*
			 * Substrings that mark a decline as "not enough money" rather than
			 * anything else. Matched against the gateway's own message, because
			 * only the gateway knows why it said no.
			 */
			'lowFunds'    => array(
				'insufficient', 'insufficient balance', 'not enough', 'solde',
				'insuffisant', 'insuffisante', 'fonds', 'balance is low',
				'low balance', 'recharge',
			),
			'i18n'        => array(
				'title'        => __( 'Vérifiez votre commande', 'delicat-builder-v9' ),
				'subtitle'     => __( 'Vérifiez tous les articles de votre panier.', 'delicat-builder-v9' ),
				'secure'       => __( 'Paiement sécurisé WooCommerce', 'delicat-builder-v9' ),
				'delay'        => __( 'Délai selon le produit', 'delicat-builder-v9' ),
				'loading'      => __( 'Préparation de votre commande…', 'delicat-builder-v9' ),
				'paying'       => __( 'Paiement en cours…', 'delicat-builder-v9' ),
				'close'        => __( 'Fermer', 'delicat-builder-v9' ),
				'lowTitle'     => __( 'Solde insuffisant', 'delicat-builder-v9' ),
				'lowBody'      => __( 'Votre solde ne couvre pas cette commande. Rechargez votre compte pour continuer.', 'delicat-builder-v9' ),
				'lowAction'    => __( 'Recharger mon compte', 'delicat-builder-v9' ),
				'lowSecondary' => __( 'Retour au paiement', 'delicat-builder-v9' ),
			),
		);
	}

	/**
	 * Where "Recharger mon compte" goes.
	 *
	 * The merchant's own page if one of the usual slugs exists, then the wallet
	 * plugin's account endpoint, and a filter for a store that calls it
	 * something else. Empty means no button is offered at all - a dead link on
	 * a payment screen is worse than no link.
	 */
	private static function wallet_url(): string {
		foreach ( array( 'my-wallet', 'wallet', 'mon-portefeuille', 'portefeuille', 'recharger-mon-compte' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
				return (string) get_permalink( $page );
			}
		}

		$endpoint = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'woo-wallet' ) : '';

		/** @param string $endpoint */
		return (string) apply_filters( 'delicat_builder_v9_express_sheet_wallet_url', $endpoint );
	}

	/**
	 * The panel, empty.
	 *
	 * It contains nothing until the customer asks for it: no checkout markup is
	 * rendered into a product page, which matters because a product page is
	 * publicly cached and a checkout form carries a per-customer nonce.
	 */
	public static function render(): void {
		if ( ! self::active() ) {
			return;
		}

		$i18n = self::config()['i18n'];
		?>
		<dialog class="dxs" id="dxs-sheet" aria-label="<?php echo esc_attr( $i18n['title'] ); ?>"
			style="--dxs-paying:<?php echo esc_attr( wp_json_encode( $i18n['paying'] ) ); ?>">

			<button type="button" class="dxs__close" data-dxs-close aria-label="<?php echo esc_attr( $i18n['close'] ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
			</button>

			<?php /* tabindex/autofocus so opening focuses the panel, not the one control that discards it. */ ?>
			<div class="dxs__scroll" data-dxs-scroll tabindex="-1" autofocus>
				<div class="dxs__grip" aria-hidden="true"></div>

				<div class="dxs__head">
					<span class="dxs__mark" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
					</span>
					<h2 class="dxs__title"><?php echo esc_html( $i18n['title'] ); ?></h2>
					<p class="dxs__sub"><?php echo esc_html( $i18n['subtitle'] ); ?></p>
				</div>

				<div class="dxs__body" data-dxs-body>
					<div class="dxs__loading" data-dxs-loading>
						<span class="dxs__spinner" aria-hidden="true"></span>
						<p><?php echo esc_html( $i18n['loading'] ); ?></p>
					</div>
				</div>
			</div>

			<div class="dxs__trust" data-dxs-trust hidden>
				<span><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8.2 10.5V7.6a3.8 3.8 0 0 1 7.6 0v2.9"/></svg><?php echo esc_html( $i18n['secure'] ); ?></span>
				<span><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.2 2.5 5.4 13.1h5.6L10.8 21.5l7.8-10.6H13Z"/></svg><?php echo esc_html( $i18n['delay'] ); ?></span>
			</div>
		</dialog>
		<?php
	}
}
