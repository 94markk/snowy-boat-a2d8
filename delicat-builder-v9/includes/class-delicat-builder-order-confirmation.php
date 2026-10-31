<?php
/**
 * Order confirmation (Builder 9.4.0): the « Commande reçue » page module.
 *
 * Replaces « Delicat Order Confirmation Pro » 2.3.1 inside the Builder. Booted by the
 * Runtime Router on the order-received endpoint, and by the REST-light profile for its two
 * routes only. WooCommerce stays the authority for the order, its key check and its hooks:
 * the page template fires them in WooCommerce's own order (§9.4 of the design spec).
 *
 * Live status: POST /wp-json/delicat-builder-v9/v1/order-status with a signed, expiring,
 * read-only token scoped to one order; customer orders also need the logged-in owner (or a
 * shop manager) and the wp_rest nonce. Rate limited, never cached, and the same 404 whether
 * the order is missing or access is refused. The old GET /delicat-order/v1/status/{id} stays
 * answered from the same model (deprecated, removal planned for 9.6.0).
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page ownership, assets, template data, receipt print root and REST routes.
 */
final class Delicat_Builder_V9_Order_Confirmation {

	public const ROUTE_NS     = 'delicat-builder-v9/v1';
	public const ROUTE        = '/order-status';
	public const LEGACY_NS    = 'delicat-order/v1';
	public const LEGACY_ROUTE = '/status/(?P<order_id>\d+)';
	public const HANDLE       = 'delicat-builder-v9-order-confirmation';

	/** @var bool */
	private static $booted = false;

	/** @var bool|null */
	private static $owns = null;

	/** @var WC_Order|int|null Order of this page (0 = none or not viewable). */
	private static $order = null;

	/** @var array|null Receipt kept for the print root (wp_footer). */
	private static $print = null;

	/** @var int Retry-After of the last refused REST call. */
	private static $retry_after = 0;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'rest_headers' ), 10, 3 );
		if ( defined( 'DELICAT_BUILDER_V9_REST_LIGHT' ) && DELICAT_BUILDER_V9_REST_LIGHT ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 1 );
		/* wc_get_template, not woocommerce_locate_template: WooCommerce caches what the
		 * latter returns in the object cache, so a persistent cache could keep serving
		 * either template after the setting changed. This filter runs on every render. */
		add_filter( 'wc_get_template', array( __CLASS__, 'locate_template' ), 20, 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 45 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 45 );
		add_action( 'wp_head', array( __CLASS__, 'head_meta' ), 2 );
		add_action( 'wp_footer', array( __CLASS__, 'print_root' ), 20 );
	}

	/** Forget per-request state (tests). */
	public static function reset(): void {
		self::$owns        = null;
		self::$order       = null;
		self::$print       = null;
		self::$retry_after = 0;
	}

	public static function settings(): array {
		return class_exists( 'Delicat_Builder_V9_Order_Confirmation_Compat', false )
			? Delicat_Builder_V9_Order_Confirmation_Compat::settings()
			: array();
	}

	public static function template_path( string $name = 'thankyou.php' ): string {
		return DELICAT_BUILDER_V9_DIR . 'templates/order-confirmation/' . $name;
	}

	/**
	 * Whether this module renders the current order-received page. Never while the old
	 * plugin is active and was not neutralised (oc_legacy_takeover = 0, the rollback path):
	 * it then renders the page alone, so there is never a second confirmation UI.
	 */
	public static function owns_page(): bool {
		if ( null !== self::$owns ) {
			return self::$owns;
		}
		$compat = 'Delicat_Builder_V9_Order_Confirmation_Compat';
		$owns   = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' )
			&& class_exists( $compat, false )
			&& $compat::enabled()
			&& ! ( $compat::legacy_loaded() && ! $compat::neutralized() )
			&& is_readable( self::template_path() );
		if ( $owns ) {
			$owns = (bool) apply_filters( 'delicat_builder_order_confirmation_enabled', true, self::current_order() );
		}
		if ( did_action( 'wp' ) ) {
			self::$owns = $owns;
		}
		return $owns;
	}

	/** The order of this order-received page when the visitor may see it, else false. */
	public static function current_order() {
		if ( null === self::$order ) {
			$id          = absint( get_query_var( 'order-received' ) );
			$order       = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
			self::$order = $order && self::can_view( $order ) ? $order : 0;
		}
		return self::$order ? self::$order : false;
	}

	/**
	 * Defence in depth on top of WooCommerce's own key check: the order key from the URL,
	 * the logged-in customer, or a shop manager.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function can_view( $order ): bool {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_order_key' ) ) ) {
			return false;
		}
		$key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- compared with hash_equals, read-only.
		if ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
			return true;
		}
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 && get_current_user_id() === $customer ) {
			return true;
		}
		return current_user_can( 'manage_woocommerce' );
	}

	// ------------------------------------------------------------------
	// Page hooks
	// ------------------------------------------------------------------

	/** template_redirect@1: private headers, and no WooCommerce details table under the page. */
	public static function send_headers(): void {
		if ( ! self::owns_page() ) {
			return;
		}
		remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( headers_sent() ) {
			return;
		}
		header( 'Referrer-Policy: same-origin', true );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
	}

	/** checkout/thankyou.php → the module template (theme overrides bypassed on purpose). */
	public static function locate_template( $template, $template_name = '', $args = array(), $template_path = '', $default_path = '' ) {
		unset( $args, $template_path, $default_path );
		if ( 'checkout/thankyou.php' !== $template_name || ! self::owns_page() ) {
			return $template;
		}
		$own = self::template_path();
		return is_readable( $own ) ? $own : $template;
	}

	public static function enqueue(): void {
		if ( ! self::owns_page() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, DELICAT_BUILDER_V9_URL . 'assets/css/order-confirmation.css', array(), DELICAT_BUILDER_V9_VERSION );
		if ( ! empty( self::settings()['oc_share'] ) ) {
			/* media="print": downloaded without blocking the first paint. */
			wp_enqueue_style( self::HANDLE . '-print', DELICAT_BUILDER_V9_URL . 'assets/css/order-confirmation-print.css', array( self::HANDLE ), DELICAT_BUILDER_V9_VERSION, 'print' );
		}
		wp_enqueue_script(
			self::HANDLE,
			DELICAT_BUILDER_V9_URL . 'assets/js/order-confirmation.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( ! self::owns_page() ) {
			return $classes;
		}
		$classes[] = 'dlc-oc-page';
		$order     = self::current_order();
		if ( $order ) {
			$state     = Delicat_Builder_V9_Order_Status::state( $order );
			$classes[] = 'dlc-oc-phase-' . sanitize_html_class( $state['phase'] );
		}
		return $classes;
	}

	/** wp_head@2: no phone / e-mail auto-links (the Player ID turned blue on iOS). */
	public static function head_meta(): void {
		if ( ! self::owns_page() ) {
			return;
		}
		echo '<meta name="format-detection" content="telephone=no, email=no, address=no">' . "\n";
		echo '<meta name="referrer" content="same-origin">' . "\n";
	}

	/** Keep the receipt for the print root printed in the footer. */
	public static function remember_receipt( array $receipt ): void {
		self::$print = $receipt;
	}

	/** wp_footer@20: the A5 print sheet (the only thing a print ever shows on this page). */
	public static function print_root(): void {
		if ( ! self::owns_page() || ! is_array( self::$print ) || empty( self::settings()['oc_share'] ) ) {
			return;
		}
		$receipt = self::$print;
		$qr_svg  = '';
		if ( ! empty( $receipt['link'] ) && class_exists( 'Delicat_Builder_V9_Qr', false ) ) {
			$matrix = Delicat_Builder_V9_Qr::matrix( (string) $receipt['link'] );
			$qr_svg = is_array( $matrix ) ? Delicat_Builder_V9_Qr::svg( $matrix, 96 ) : '';
		}
		$file = self::template_path( 'receipt-print.php' );
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Codes zone: re-fires the hooks WooCommerce's details table carried, so the Digital
	 * Gateway (live sync @4, delivery panel @5) and Shop2TopUp (vouchers on item_meta_end)
	 * render on this page. WooCommerce's « Commander à nouveau » button is left out.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_codes_zone( $order ): string {
		$html = '';
		/* Shop2TopUp's voucher labels in French, for this zone only (its own strings, its own
		 * text domain, exact msgids: the codes and the plugin's markup are untouched). */
		add_filter( 'gettext_delicat-shop2topup', array( __CLASS__, 'supplier_label' ), 20, 3 );
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			ob_start();
			do_action( 'woocommerce_order_item_meta_start', $item_id, $item, $order, false );
			do_action( 'woocommerce_order_item_meta_end', $item_id, $item, $order, false );
			$out = trim( (string) ob_get_clean() );
			if ( '' !== $out ) {
				$html .= '<div class="dlc-oc-codes__line"><p class="dlc-oc-codes__name">' . esc_html( wp_strip_all_tags( (string) $item->get_name() ) ) . '</p>' . $out . '</div>';
			}
		}
		$again = has_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button' );
		if ( false !== $again ) {
			remove_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button', $again );
		}
		ob_start();
		do_action( 'woocommerce_order_details_after_order_table', $order );
		$html .= trim( (string) ob_get_clean() );
		if ( false !== $again ) {
			add_action( 'woocommerce_order_details_after_order_table', 'woocommerce_order_again_button', $again );
		}
		remove_filter( 'gettext_delicat-shop2topup', array( __CLASS__, 'supplier_label' ), 20 );
		return $html;
	}

	/**
	 * gettext_delicat-shop2topup, inside render_codes_zone() only: the English voucher labels
	 * of Shop2TopUp's own markup (« Voucher codes », « Serial: », « Expires: ») in the page's
	 * French. Any other string of that domain, and every other domain, passes through.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original msgid.
	 * @param string $domain      Text domain.
	 */
	public static function supplier_label( $translation, $text = '', $domain = '' ) {
		if ( 'delicat-shop2topup' !== $domain || ! is_string( $text ) ) {
			return $translation;
		}
		switch ( $text ) {
			case 'Voucher codes':
				return __( 'Codes', 'delicat-builder-v9' );
			case 'Voucher codes:':
				return Delicat_Builder_V9_Order_Status::fr( __( 'Codes :', 'delicat-builder-v9' ) );
			case 'Serial:':
				return Delicat_Builder_V9_Order_Status::fr( __( "N\u{00B0}\u{00A0}de série :", 'delicat-builder-v9' ) );
			case 'Expires:':
				return Delicat_Builder_V9_Order_Status::fr( __( 'Expire le :', 'delicat-builder-v9' ) );
		}
		return $translation;
	}

	/**
	 * Support link: the saved link, else WhatsApp Business (9.4.2: the message names the order),
	 * else the old plugin's filter, else /contact/.
	 */
	public static function support_url( $order = null ): string {
		$url = (string) ( self::settings()['oc_support_url'] ?? '' );
		if ( '' === $url ) {
			$wa = self::whatsapp_number();
			if ( '' !== $wa ) {
				$number = is_object( $order ) && is_callable( array( $order, 'get_order_number' ) ) ? (string) $order->get_order_number() : '';
				$text   = '' !== $number
					/* translators: %s: order number */
					? sprintf( __( 'Bonjour Delicat Store, j’ai besoin d’aide pour ma commande #%s.', 'delicat-builder-v9' ), $number )
					: __( 'Bonjour Delicat Store, j’ai besoin d’aide pour ma commande.', 'delicat-builder-v9' );
				$url    = 'https://wa.me/' . $wa . '?text=' . rawurlencode( $text );
			} else {
				$url = (string) apply_filters( 'delicat_order_support_url', home_url( '/contact/' ), $order );
			}
		}
		return esc_url_raw( $url );
	}

	/** 9.4.2: WhatsApp Business number of the support team (digits), or ''. */
	public static function whatsapp_number(): string {
		$raw = (string) ( self::settings()['oc_whatsapp'] ?? '' );
		return class_exists( 'Delicat_Builder_V9_Order_Confirmation_Compat', false ) ? Delicat_Builder_V9_Order_Confirmation_Compat::clean_whatsapp( $raw ) : (string) preg_replace( '/[^0-9]/', '', $raw );
	}

	/** 9.4.2: « +509 3311 1283 » for a Haitian number, « +<digits> » otherwise. */
	public static function whatsapp_label( string $digits ): string {
		if ( 11 === strlen( $digits ) && 0 === strpos( $digits, '509' ) ) {
			return '+509 ' . substr( $digits, 3, 4 ) . ' ' . substr( $digits, 7 );
		}
		return '' !== $digits ? '+' . $digits : '';
	}

	/**
	 * Everything the template prints, escaped later by the template.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state Delicat_Builder_V9_Order_Status::state().
	 */
	public static function view( $order, array $state ): array {
		$s        = self::settings();
		$currency = Delicat_Builder_V9_Order_Status::currency( $order );
		$lines    = array();
		$player   = '';
		$index    = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$fields = Delicat_Builder_V9_Order_Status::player_fields( $item );
			$chips  = array();
			if ( '' !== $fields['player'] ) {
				/* translators: %s: player ID */
				$chips[] = sprintf( __( 'ID joueur : %s', 'delicat-builder-v9' ), $fields['player'] );
				$player  = '' === $player ? $fields['player'] : $player;
			}
			if ( '' !== $fields['server'] ) {
				/* translators: %s: server or zone */
				$chips[] = sprintf( __( 'Serveur : %s', 'delicat-builder-v9' ), $fields['server'] );
			}
			if ( '' !== $fields['name'] ) {
				/* translators: %s: player name */
				$chips[] = sprintf( __( 'Joueur : %s', 'delicat-builder-v9' ), $fields['name'] );
			}
			$lines[] = array(
				'name'  => wp_strip_all_tags( (string) $item->get_name() ),
				'qty'   => max( 1, (int) $item->get_quantity() ),
				'thumb' => self::thumbnail( $item ),
				'chips' => array_map( array( 'Delicat_Builder_V9_Order_Status', 'fr' ), $chips ),
				'total' => Delicat_Builder_V9_Order_Status::money( Delicat_Builder_V9_Order_Status::line_amount( $item ), $currency ),
				'state' => (string) ( $state['delivery']['rows'][ $index ] ?? 'waiting' ),
			);
			++$index;
		}
		$count    = 0;
		foreach ( $lines as $line ) {
			$count += $line['qty'];
		}
		$customer = null;
		if ( ! empty( $s['oc_show_customer'] ) && (int) $order->get_customer_id() === get_current_user_id() ) {
			$customer = array_filter(
				array(
					'name'  => trim( wp_strip_all_tags( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
					'email' => sanitize_email( (string) $order->get_billing_email() ),
					'phone' => trim( wp_strip_all_tags( (string) $order->get_billing_phone() ) ),
				)
			);
		}
		$app_view = class_exists( 'Delicat_Builder_V9_App_View', false ) && Delicat_Builder_V9_App_View::active();
		$shop     = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';
		$paid     = ! empty( $state['paid'] );
		$total    = Delicat_Builder_V9_Order_Status::money( (float) $order->get_total(), $currency );
		$owner    = (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === get_current_user_id();
		$live     = ! empty( $s['oc_live'] ) && empty( $state['terminal'] ) && ( 0 === (int) $order->get_customer_id() || $owner || current_user_can( 'manage_woocommerce' ) );
		return array(
			'number'      => (string) $order->get_order_number(),
			'date'        => Delicat_Builder_V9_Order_Status::date_label( $order->get_date_created() ),
			'payment'     => Delicat_Builder_V9_Order_Status::payment_label( $order ),
			'lines'       => $lines,
			/* translators: %d: number of items */
			'count_label' => sprintf( _n( '%d article', '%d articles', $count, 'delicat-builder-v9' ), $count ),
			'rows'        => Delicat_Builder_V9_Order_Status::amount_rows( $order ),
			'total'       => $total,
			'total_label' => (string) ( $state['total_label'] ?? ( $paid ? __( 'Total payé', 'delicat-builder-v9' ) : __( 'Total', 'delicat-builder-v9' ) ) ),
			/* A long total gets the full summary row on phones (« 1 250 015,50 HTG » overflowed half a tile). */
			'total_wide'  => ( function_exists( 'mb_strlen' ) ? mb_strlen( $total, 'UTF-8' ) : strlen( $total ) ) > 10,
			'customer'    => $customer,
			'support_url' => self::support_url( $order ),
			'support_wa'  => 0 === strpos( self::support_url( $order ), 'https://wa.me/' ),
			'support_num' => self::whatsapp_label( self::whatsapp_number() ),
			'orders_url'  => is_user_logged_in() && function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'orders' ) : '',
			'shop_url'    => $app_view || '' === $shop ? home_url( '/' ) : $shop,
			'pay_url'     => ! $paid && (float) $order->get_total() > 0 && is_callable( array( $order, 'get_checkout_payment_url' ) ) ? (string) $order->get_checkout_payment_url() : '',
			'note'        => in_array( $state['phase'], array( 'paid', 'sending' ), true ) ? trim( (string) ( $s['oc_note'] ?? '' ) ) : '',
			'codes_title' => $state['delivery']['has_code_line'] ? __( 'Vos codes', 'delicat-builder-v9' ) : __( 'Votre livraison', 'delicat-builder-v9' ),
			'player'      => $player,
			/* « Partager le reçu », its sheet and the print sheet: the setting, and a paid phase only. */
			'share'       => ! empty( $s['oc_share'] ) && ! empty( $state['shareable'] ),
			'motion'      => (string) ( $s['oc_motion'] ?? 'full' ),
			/* « Mon compte › Commandes » hint: signed-in owners only (guests have no account page). */
			'codes_hint'  => $owner && ! empty( $state['delivery']['has_code_line'] ) && is_user_logged_in(),
			'account_url' => function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'orders' ) : '',
			/* The hero CTA slot keeps its height while a live change may bring a button in or out. */
			'cta_reserve' => $live,
			'entrance'    => self::replayed() ? 'none' : 'full',
		);
	}

	/**
	 * The page reloads itself (or the gateway reloads it) when delivery moves on: the
	 * script sets a 20 s cookie on pagehide, and the reloaded page skips the card entrance
	 * (A1) instead of replaying it. Presence only; the value is never read.
	 */
	public static function replayed(): bool {
		return isset( $_COOKIE['dlc_oc_again'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- presence only, the value is not used.
	}

	/** 48 px product thumbnail URL ('' when none). */
	private static function thumbnail( $item ): string {
		$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
		if ( ! is_object( $product ) || ! function_exists( 'wp_get_attachment_image_url' ) ) {
			return '';
		}
		$image = (int) $product->get_image_id();
		if ( ! $image && $product->get_parent_id() && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$image  = $parent ? (int) $parent->get_image_id() : 0;
		}
		return $image ? (string) wp_get_attachment_image_url( $image, 'thumbnail' ) : '';
	}

	/** Page configuration read by order-confirmation.js (no inline script). */
	public static function config( $order, array $state ): array {
		$s        = self::settings();
		$customer = (int) $order->get_customer_id();
		$user     = get_current_user_id();
		/* A customer order seen while signed out cannot be polled (the route wants its owner). */
		$can_poll = 0 === $customer || $user === $customer || current_user_can( 'manage_woocommerce' );
		return array(
			'endpoint'    => rest_url( self::ROUTE_NS . self::ROUTE ),
			'nonce'       => $user > 0 ? wp_create_nonce( 'wp_rest' ) : '',
			'order'       => (int) $order->get_id(),
			'token'       => self::token( $order ),
			'phase'       => $state['phase'],
			'fingerprint' => $state['fingerprint'],
			'terminal'    => (bool) $state['terminal'],
			'live'        => ! empty( $s['oc_live'] ) && $can_poll && ! $state['terminal'],
			'maxMinutes'  => (int) ( $s['oc_poll_minutes'] ?? 30 ),
			'motion'      => (string) ( $s['oc_motion'] ?? 'full' ),
			'celebrate'   => ! empty( $s['oc_celebrate'] ),
			/* Sharing is on in the settings: a page opened before payment reloads once the order is
			 * paid, so the receipt (rendered on the server, never sent by the status route) appears. */
			'share'       => ! empty( $s['oc_share'] ),
			'receiptJs'   => ! empty( $s['oc_share'] ) && ! empty( $state['shareable'] ) ? self::asset_url( 'assets/js/order-confirmation-receipt.js' ) : '',
			'i18n'        => self::i18n(),
		);
	}

	/** A plugin asset URL with its content-hash ?ver= (asset-versions.php), like enqueued files. */
	private static function asset_url( string $relative ): string {
		$url = DELICAT_BUILDER_V9_URL . $relative . '?ver=' . rawurlencode( DELICAT_BUILDER_V9_VERSION );
		if ( class_exists( 'Delicat_Builder_V9_Audit_Fixes', false ) && is_callable( array( 'Delicat_Builder_V9_Audit_Fixes', 'asset_url' ) ) ) {
			$url = (string) Delicat_Builder_V9_Audit_Fixes::asset_url( $url );
		}
		return esc_url_raw( $url );
	}

	/** Every string the script writes. */
	public static function i18n(): array {
		$fr = array( 'Delicat_Builder_V9_Order_Status', 'fr' );
		return array_map(
			$fr,
			array(
				'live'      => __( 'En direct', 'delicat-builder-v9' ),
				/* translators: %s: elapsed time */
				'ago'       => __( '· vérifié il y a %s', 'delicat-builder-v9' ),
				'now'       => __( '· vérifié à l’instant', 'delicat-builder-v9' ),
				'sec'       => __( '%d s', 'delicat-builder-v9' ),
				'min'       => __( '%d min', 'delicat-builder-v9' ),
				/* translators: %s: time */
				'final'     => __( 'Statut final · vérifié à %s', 'delicat-builder-v9' ),
				'paused'    => __( 'En pause', 'delicat-builder-v9' ),
				'offline'   => __( 'Hors ligne — reprise automatique', 'delicat-builder-v9' ),
				'unstable'  => __( 'Connexion instable — nouvel essai…', 'delicat-builder-v9' ),
				'stopped'   => __( 'Actualisez la page pour reprendre le suivi', 'delicat-builder-v9' ),
				'idle'      => __( 'Suivi en pause', 'delicat-builder-v9' ),
				/* translators: 1: status, 2: headline */
				'announce'  => __( 'Statut mis à jour : %1$s. %2$s', 'delicat-builder-v9' ),
				'copy'      => __( 'Copier', 'delicat-builder-v9' ),
				'copied'    => __( 'Copié !', 'delicat-builder-v9' ),
				'copyCode'  => __( 'Copier le code', 'delicat-builder-v9' ),
				'linkDone'  => __( 'Lien copié', 'delicat-builder-v9' ),
				'codeDone'  => __( 'Code copié', 'delicat-builder-v9' ),
				'copyFail'  => __( 'Copie impossible. Sélectionnez le texte.', 'delicat-builder-v9' ),
				'shareFail' => __( 'Le reçu n’a pas pu être partagé. Réessayez.', 'delicat-builder-v9' ),
				'saved'     => __( 'Reçu téléchargé', 'delicat-builder-v9' ),
				'done'      => __( 'terminé', 'delicat-builder-v9' ),
				'ended'     => __( 'arrêté', 'delicat-builder-v9' ),
				'date'      => __( 'Date', 'delicat-builder-v9' ),
				'payment'   => __( 'Paiement', 'delicat-builder-v9' ),
				/* translators: %s: player ID */
				'id'        => __( 'ID : %s', 'delicat-builder-v9' ),
				/* translators: %d: number of lines */
				'more'      => __( '+ %d autres articles', 'delicat-builder-v9' ),
				'waiting'   => __( 'En préparation', 'delicat-builder-v9' ),
				'moving'    => __( 'En cours', 'delicat-builder-v9' ),
				'delivered' => __( 'Livrée', 'delicat-builder-v9' ),
				'review'    => __( 'À vérifier', 'delicat-builder-v9' ),
				'refunded'  => __( 'Remboursée', 'delicat-builder-v9' ),
				'total'     => __( 'TOTAL', 'delicat-builder-v9' ),
				'totalPaid' => __( 'TOTAL PAYÉ', 'delicat-builder-v9' ),
			)
		);
	}

	// ------------------------------------------------------------------
	// Token and rate limit
	// ------------------------------------------------------------------

	/**
	 * Signed, read-only, expiring token for one order: v1.<exp>.<hmac>.
	 *
	 * @param WC_Order $order Order.
	 * @param int      $exp   Expiry (0 = poll duration + 10 min, capped at 2 h).
	 */
	public static function token( $order, int $exp = 0 ): string {
		if ( $exp <= 0 ) {
			$minutes = (int) ( self::settings()['oc_poll_minutes'] ?? 30 );
			$exp     = time() + min( 7200, $minutes * 60 + 600 );
		}
		return 'v1.' . $exp . '.' . hash_hmac( 'sha256', 'dlc-oc|' . $order->get_id() . '|' . $order->get_order_key() . '|' . (int) $order->get_customer_id() . '|' . $exp, wp_salt( 'auth' ) );
	}

	/** Token signature and expiry for this order. */
	public static function verify_token( $token, $order ): bool {
		if ( ! is_string( $token ) || ! preg_match( '/^v1\.(\d{9,11})\.([a-f0-9]{64})$/', $token, $m ) ) {
			return false;
		}
		$exp = (int) $m[1];
		if ( $exp < time() || $exp > time() + 7260 ) {
			return false;
		}
		return hash_equals( self::token( $order, $exp ), $token );
	}

	/** Customer orders: their signed-in owner or a shop manager. Guests: the token is enough. */
	private static function viewer_allowed( $order ): bool {
		$customer = (int) $order->get_customer_id();
		if ( $customer <= 0 ) {
			return true;
		}
		return get_current_user_id() === $customer || current_user_can( 'manage_woocommerce' );
	}

	private static function client_ip(): string {
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'client_ip' ) ) ) {
			return (string) Delicat_Builder_V9_Security::client_ip();
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated just below, hashed before storage.
		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : 'unknown';
	}

	/** Refused checks (missing order, bad token, wrong viewer) per client and minute: stops scanning. */
	public const FAILS_PER_IP = 30;

	/** Answered polls per (order, client) and minute; normal polling stays under 20. */
	public const POLLS_PER_ORDER = 40;

	/**
	 * Count one hit; return the seconds to wait (0 = allowed).
	 *
	 * @param string $bucket Bucket name (already free of raw IPs).
	 * @param int    $limit  Hits per window.
	 * @param int    $window Window in seconds.
	 */
	private static function hit( string $bucket, int $limit, int $window = 60 ): int {
		$key = 'dlc_oc_' . md5( $bucket );
		$now = time();
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$slot = $key . '_' . (int) floor( $now / $window );
			wp_cache_add( $slot, 0, 'dlc_oc', $window + 5 );
			$count = wp_cache_incr( $slot, 1, 'dlc_oc' );
			if ( false !== $count ) {
				return (int) $count > $limit ? max( 1, $window - ( $now % $window ) ) : 0;
			}
		}
		$data = get_transient( $key );
		if ( ! is_array( $data ) || count( $data ) < 2 || $now - (int) $data[1] >= $window ) {
			$data = array( 0, $now );
		}
		$data[0] = (int) $data[0] + 1;
		set_transient( $key, $data, $window );
		return $data[0] > $limit ? max( 1, $window - ( $now - (int) $data[1] ) ) : 0;
	}

	/**
	 * Seconds to wait when a bucket is already full (0 = allowed). Reads only: a refused
	 * request writes nothing.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Hits per window.
	 * @param int    $window Window in seconds.
	 */
	private static function full( string $bucket, int $limit, int $window = 60 ): int {
		$key = 'dlc_oc_' . md5( $bucket );
		$now = time();
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$count = wp_cache_get( $key . '_' . (int) floor( $now / $window ), 'dlc_oc' );
			if ( false !== $count ) {
				return (int) $count >= $limit ? max( 1, $window - ( $now % $window ) ) : 0;
			}
		}
		$data = get_transient( $key );
		if ( ! is_array( $data ) || count( $data ) < 2 || $now - (int) $data[1] >= $window ) {
			return 0;
		}
		return (int) $data[0] >= $limit ? max( 1, $window - ( $now - (int) $data[1] ) ) : 0;
	}

	/** md5 of the client IP: the only form ever stored. */
	private static function client_key(): string {
		return md5( self::client_ip() );
	}

	/**
	 * Shared gate of both routes: a client over its refusal budget is slowed down before any
	 * lookup; a refusal counts against that budget (one row per client, never per guessed
	 * order); only a verified poll counts against the per-order budget.
	 *
	 * @param int  $order_id Order ID asked for.
	 * @param bool $verified Whether the order exists and the request may read it.
	 * @return true|WP_Error
	 */
	private static function gate( int $order_id, bool $verified ) {
		$client = self::client_key();
		if ( ! $verified ) {
			self::hit( 'ip|' . $client, self::FAILS_PER_IP );
			return self::not_found();
		}
		$retry = self::hit( 'o|' . $order_id . '|' . $client, self::POLLS_PER_ORDER );
		return $retry > 0 ? self::slow_down( $retry ) : true;
	}

	/** Retry-After when this client already used its refusal budget, else 0 (writes nothing). */
	public static function rate_limited( int $order_id = 0 ): int {
		unset( $order_id );
		return self::full( 'ip|' . self::client_key(), self::FAILS_PER_IP );
	}

	// ------------------------------------------------------------------
	// REST
	// ------------------------------------------------------------------

	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_status' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'show_in_index'       => false,
			)
		);
		$compat = 'Delicat_Builder_V9_Order_Confirmation_Compat';
		if ( ! class_exists( $compat, false ) || ! $compat::legacy_loaded() || $compat::neutralized() ) {
			register_rest_route(
				self::LEGACY_NS,
				self::LEGACY_ROUTE,
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_legacy_status' ),
					'permission_callback' => array( __CLASS__, 'rest_legacy_permission' ),
					'show_in_index'       => false,
				)
			);
		}
	}

	private static function not_found(): WP_Error {
		return new WP_Error( 'dlc_oc_not_found', __( 'Commande introuvable.', 'delicat-builder-v9' ), array( 'status' => 404 ) );
	}

	private static function slow_down( int $retry ): WP_Error {
		self::$retry_after = $retry;
		return new WP_Error( 'dlc_oc_slow_down', __( 'Trop de requêtes. Réessayez dans un instant.', 'delicat-builder-v9' ), array( 'status' => 429 ) );
	}

	private static function no_store(): void {
		if ( function_exists( 'nocache_headers' ) && ! headers_sent() ) {
			nocache_headers();
		}
		do_action( 'litespeed_control_set_nocache', 'delicat order status' );
	}

	private static function scalar_param( $request, string $name ): string {
		$value = $request->get_param( $name );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Refusal budget, then order, token and owner, then the per-order budget; every refusal
	 * is the same 404 as a missing order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function rest_permission( $request ) {
		self::$retry_after = 0;
		self::no_store();
		$retry = self::rate_limited();
		if ( $retry > 0 ) {
			return self::slow_down( $retry );
		}
		$order_id = absint( self::scalar_param( $request, 'order' ) );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		return self::gate( $order_id, $order && self::verify_token( self::scalar_param( $request, 'token' ), $order ) && self::viewer_allowed( $order ) );
	}

	/** 200: the state object (no greeting), never codes, billing fields or meta values. */
	public static function rest_status( $request ) {
		$order = wc_get_order( absint( self::scalar_param( $request, 'order' ) ) );
		if ( ! $order ) {
			return self::not_found();
		}
		$state = Delicat_Builder_V9_Order_Status::state( $order, 'rest' );
		unset( $state['greeting'] );
		$state['v']          = 1;
		$state['order']      = (string) $order->get_order_number();
		$state['checked_at'] = gmdate( 'c' );
		$state['reload']     = in_array( $state['phase'], array( 'delivered', 'partial' ), true );
		return new WP_REST_Response( $state, 200 );
	}

	/** Old route: old token formula, same rate limit, same uniform 404. */
	public static function rest_legacy_permission( $request ) {
		self::$retry_after = 0;
		self::no_store();
		$retry = self::rate_limited();
		if ( $retry > 0 ) {
			return self::slow_down( $retry );
		}
		$order_id = absint( self::scalar_param( $request, 'order_id' ) );
		$order    = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		$token    = self::scalar_param( $request, 'token' );
		return self::gate( $order_id, $order && '' !== $token && hash_equals( hash_hmac( 'sha256', 'docp|' . $order->get_id() . '|' . $order->get_order_key(), wp_salt( 'auth' ) ), $token ) );
	}

	/** Old keys, answered from the new model (tabs still open on the old page keep working). */
	public static function rest_legacy_status( $request ) {
		$order = wc_get_order( absint( self::scalar_param( $request, 'order_id' ) ) );
		if ( ! $order ) {
			return self::not_found();
		}
		$state  = Delicat_Builder_V9_Order_Status::state( $order, 'legacy' );
		$done   = 0;
		$active = 0;
		foreach ( $state['steps'] as $i => $step ) {
			if ( 'done' === $step['state'] ) {
				++$done;
			} elseif ( 0 === $active && in_array( $step['state'], array( 'current', 'alert' ), true ) ) {
				$active = $i + 1;
			}
		}
		$modified = $order->get_date_modified();
		return new WP_REST_Response(
			array(
				'order_id'   => (int) $order->get_id(),
				'status'     => (string) $order->get_status(),
				'label'      => $state['pill']['label'],
				'hero'       => $state['headline'],
				'subtitle'   => $state['message'],
				'progress'   => $done,
				'active'     => $active,
				'tone'       => 'accent' === $state['tone'] ? 'processing' : $state['tone'],
				'terminal'   => (bool) $state['terminal'],
				'updated_at' => $modified ? wp_date( 'H:i:s', $modified->getTimestamp() ) : wp_date( 'H:i:s' ),
				'checked_at' => gmdate( 'c' ),
				'deprecated' => true,
			),
			200
		);
	}

	/**
	 * rest_post_dispatch: private, never-cached headers on every answer of both routes
	 * (200, 404, 429 and malformed requests alike).
	 *
	 * @param WP_HTTP_Response|mixed $response Response.
	 * @param mixed                  $server   Server.
	 * @param WP_REST_Request|mixed  $request  Request.
	 */
	public static function rest_headers( $response, $server = null, $request = null ) {
		unset( $server );
		if ( ! is_object( $response ) || ! is_callable( array( $response, 'header' ) ) || ! is_object( $request ) || ! is_callable( array( $request, 'get_route' ) ) ) {
			return $response;
		}
		$route  = (string) $request->get_route();
		$own    = 0 === strpos( $route, '/' . self::ROUTE_NS . self::ROUTE );
		$legacy = 0 === strpos( $route, '/' . self::LEGACY_NS . '/status/' );
		if ( ! $own && ! $legacy ) {
			return $response;
		}
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Vary', 'Cookie' );
		if ( self::$retry_after > 0 ) {
			$response->header( 'Retry-After', (string) self::$retry_after );
		}
		if ( $legacy ) {
			$response->header( 'Deprecation', 'true' );
			$response->header( 'Link', '<' . esc_url_raw( rest_url( self::ROUTE_NS . self::ROUTE ) ) . '>; rel="successor-version"' );
		}
		return $response;
	}
}
