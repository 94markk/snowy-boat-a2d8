<?php
/**
 * Order details (Builder 9.5.1): Mon compte › Commandes › « Commande #… ».
 *
 * Replaces WooCommerce's myaccount/view-order.php with an app-style page built on the same
 * order model as the « Commande reçue » page (Delicat_Builder_V9_Order_Status): status pill
 * with a Material-style glyph, four-step tracker with dates, ordered items, codes zone,
 * contact and payment cards, payment summary, order updates and actions.
 *
 * WooCommerce stays the authority: it checks that the signed-in customer may view the
 * order (current_user_can( 'view_order' )) before it loads the template, and this page
 * checks the owner again. Codes are printed only by the Digital Gateway and Shop2TopUp
 * through WooCommerce's own hooks (Delicat_Builder_V9_Order_Confirmation::render_codes_zone).
 * Private page: never cached, no referrer to other sites.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template ownership, assets and template data of the order details page.
 */
final class Delicat_Builder_V9_Order_View {

	public const HANDLE = 'delicat-builder-v9-order-view';

	/** @var bool */
	private static $booted = false;

	/** @var bool|null */
	private static $enabled = null;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 1 );
		/* wc_get_template (not woocommerce_locate_template, whose result WooCommerce caches). */
		add_filter( 'wc_get_template', array( __CLASS__, 'locate_template' ), 20, 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 46 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 46 );
		add_action( 'wp_head', array( __CLASS__, 'head_meta' ), 2 );
	}

	/** Forget per-request state (tests). */
	public static function reset(): void {
		self::$enabled = null;
	}

	public static function template_path(): string {
		return DELICAT_BUILDER_V9_DIR . 'templates/order-confirmation/view-order.php';
	}

	/** The module renders order details: the order module is on and the template is there. */
	public static function enabled(): bool {
		if ( null !== self::$enabled ) {
			return self::$enabled;
		}
		$compat  = 'Delicat_Builder_V9_Order_Confirmation_Compat';
		$enabled = class_exists( $compat, false ) && $compat::enabled()
			&& class_exists( 'Delicat_Builder_V9_Order_Status', false )
			&& class_exists( 'Delicat_Builder_V9_Order_Confirmation', false )
			&& is_readable( self::template_path() );
		$enabled = $enabled && (bool) apply_filters( 'delicat_builder_order_view_enabled', true );
		if ( did_action( 'wp' ) ) {
			self::$enabled = $enabled;
		}
		return $enabled;
	}

	public static function is_view_order(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'view-order' );
	}

	/**
	 * Account sections the engine switches in place (dashboard, orders, order details): they
	 * all load this page's style and script, so moving between them never needs a new file.
	 */
	public static function soft_section(): bool {
		if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() || ! function_exists( 'is_wc_endpoint_url' ) ) {
			return false;
		}
		return ! is_wc_endpoint_url() || is_wc_endpoint_url( 'orders' ) || is_wc_endpoint_url( 'view-order' );
	}

	// ------------------------------------------------------------------
	// Page hooks
	// ------------------------------------------------------------------

	/** template_redirect@1: private, never cached, no referrer outside the site. */
	public static function send_headers(): void {
		if ( ! self::is_view_order() || ! self::enabled() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( headers_sent() ) {
			return;
		}
		header( 'Referrer-Policy: same-origin', true );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		self::full_page_answer();
	}

	/**
	 * The engine asks for this page to switch it in place (X-Delicat-Nav: 1). When the page holds
	 * another plugin's markup that needs its own scripts at page load (codes of the Digital
	 * Gateway or Shop2TopUp, extra blocks of woocommerce_view_order), answer at once with an
	 * empty 204 and X-Delicat-Nav: full: the engine then opens it as a full page, without the
	 * server building it twice. Only for the signed-in owner; anything else renders normally.
	 */
	private static function full_page_answer(): void {
		$asked = isset( $_SERVER['HTTP_X_DELICAT_NAV'] ) && '1' === (string) $_SERVER['HTTP_X_DELICAT_NAV']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with a constant.
		if ( ! $asked || ! is_user_logged_in() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( absint( get_query_var( 'view-order' ) ) );
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() || ! self::needs_full_page( $order ) ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0', true );
		header( 'X-Delicat-Nav: full', true );
		header( 'Vary: X-Delicat-Nav, Cookie', false );
		status_header( 204 );
		exit;
	}

	/**
	 * Whether this order's page carries third-party markup: the codes zone (a paid order still
	 * standing, with a code line or the Digital Gateway), or other blocks hooked on
	 * woocommerce_view_order besides WooCommerce's own table.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function needs_full_page( $order ): bool {
		$callbacks = isset( $GLOBALS['wp_filter']['woocommerce_view_order'] ) ? $GLOBALS['wp_filter']['woocommerce_view_order']->callbacks : array();
		foreach ( (array) $callbacks as $list ) {
			foreach ( (array) $list as $callback ) {
				if ( isset( $callback['function'] ) && 'woocommerce_order_details_table' !== $callback['function'] ) {
					return true;
				}
			}
		}
		$state = Delicat_Builder_V9_Order_Status::state( $order );
		if ( empty( $state['paid'] ) || 'end' === $state['group'] ) {
			return false;
		}
		return ! empty( $state['delivery']['has_code_line'] ) || null !== apply_filters( 'delicat_app_order_delivery', null, $order, 'summary' );
	}

	/** myaccount/view-order.php → the module template (theme overrides bypassed on purpose). */
	public static function locate_template( $template, $template_name = '', $args = array(), $template_path = '', $default_path = '' ) {
		unset( $args, $template_path, $default_path );
		if ( 'myaccount/view-order.php' !== $template_name || ! self::is_view_order() || ! self::enabled() ) {
			return $template;
		}
		$own = self::template_path();
		return is_readable( $own ) ? $own : $template;
	}

	public static function enqueue(): void {
		if ( ! self::enabled() || ! self::soft_section() ) {
			return;
		}
		wp_enqueue_style( self::HANDLE, DELICAT_BUILDER_V9_URL . 'assets/css/order-view.css', array(), DELICAT_BUILDER_V9_VERSION );
		if ( ! empty( Delicat_Builder_V9_Order_Confirmation::settings()['oc_share'] ) ) {
			/* 9.5.2: receipt print sheet (media="print": never blocks the first paint). */
			wp_enqueue_style( self::HANDLE . '-print', DELICAT_BUILDER_V9_URL . 'assets/css/order-confirmation-print.css', array( self::HANDLE ), DELICAT_BUILDER_V9_VERSION, 'print' );
		}
		wp_enqueue_script(
			self::HANDLE,
			DELICAT_BUILDER_V9_URL . 'assets/js/order-view.js',
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
		if ( self::is_view_order() && self::enabled() ) {
			$classes[] = 'dlc-vo-page';
		}
		return $classes;
	}

	/** wp_head@2: Player IDs and order numbers are not phone links (iOS turned them blue). */
	public static function head_meta(): void {
		if ( ! self::is_view_order() || ! self::enabled() ) {
			return;
		}
		echo '<meta name="format-detection" content="telephone=no, email=no, address=no">' . "\n";
	}

	// ------------------------------------------------------------------
	// Template data
	// ------------------------------------------------------------------

	/** The signed-in owner of the order, or a shop manager. */
	public static function can_view( $order ): bool {
		if ( ! is_object( $order ) || ! is_callable( array( $order, 'get_customer_id' ) ) ) {
			return false;
		}
		$customer = (int) $order->get_customer_id();
		if ( $customer > 0 && get_current_user_id() === $customer ) {
			return true;
		}
		return current_user_can( 'manage_woocommerce' );
	}

	/** « 23 oct. » — French short month, site timezone. */
	private static function day_month( int $ts ): string {
		$months = array( '', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
		return wp_date( 'j', $ts ) . "\u{00A0}" . ( $months[ (int) wp_date( 'n', $ts ) ] ?? '' );
	}

	/** « 23 oct. 2026 à 15:26 ». */
	public static function long_date( $date ): string {
		if ( ! is_object( $date ) || ! is_callable( array( $date, 'getTimestamp' ) ) ) {
			return '';
		}
		$ts = (int) $date->getTimestamp();
		/* translators: 1: date, 2: time */
		return sprintf( __( '%1$s à %2$s', 'delicat-builder-v9' ), self::day_month( $ts ) . ' ' . wp_date( 'Y', $ts ), wp_date( 'H:i', $ts ) );
	}

	/**
	 * Tracker date on two lines: « 23 oct. » + « 15:26 » this year, « 23 oct. » + « 2025 » before.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function step_date( $date ): array {
		if ( ! is_object( $date ) || ! is_callable( array( $date, 'getTimestamp' ) ) ) {
			return array( '', '' );
		}
		$ts = (int) $date->getTimestamp();
		return array( self::day_month( $ts ), wp_date( 'Y', $ts ) !== wp_date( 'Y' ) ? wp_date( 'Y', $ts ) : wp_date( 'H:i', $ts ) );
	}

	/** Glyph of the status pill and of the hero illustration badge, per phase. */
	public static function phase_glyph( string $phase ): string {
		$map = array(
			'awaiting_payment' => 'schedule',
			'payment_review'   => 'verified',
			'paid'             => 'bolt',
			'sending'          => 'send',
			'partial'          => 'send',
			'review'           => 'hourglass',
			'delivered'        => 'check-circle',
			'failed'           => 'error',
			'cancelled'        => 'cancel',
			'refunded'         => 'undo',
		);
		return $map[ $phase ] ?? 'info';
	}

	/** Illustration scene per phase: a parcel on its way, a delivered parcel, a card… */
	public static function scene( string $phase ): string {
		if ( in_array( $phase, array( 'awaiting_payment', 'payment_review', 'failed' ), true ) ) {
			return 'card';
		}
		if ( 'refunded' === $phase ) {
			return 'coins';
		}
		return in_array( $phase, array( 'paid', 'sending', 'partial' ), true ) ? 'moving' : 'box';
	}

	/**
	 * Tracker steps with dates: done steps carry the date they happened, the step in
	 * progress its own words, the rest « En attente ».
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state Delicat_Builder_V9_Order_Status::state().
	 */
	public static function steps( $order, array $state ): array {
		$steps   = array();
		$reached = array();
		foreach ( $state['steps'] as $i => $step ) {
			$reached[ $i ] = in_array( $step['state'], array( 'done', 'current', 'alert', 'ended' ), true );
		}
		$glyphs = array(
			'received'  => 'receipt',
			'paid'      => 'card',
			'preparing' => 'send',
			'delivered' => 'flag',
		);
		foreach ( $state['steps'] as $i => $step ) {
			$sub  = '';
			$sub2 = '';
			if ( 'done' === $step['state'] ) {
				$date = null;
				if ( 'received' === $step['key'] ) {
					$date = $order->get_date_created();
				} elseif ( 'paid' === $step['key'] ) {
					$date = $order->get_date_paid() ? $order->get_date_paid() : ( ! empty( $state['paid'] ) ? $order->get_date_created() : null );
				} elseif ( 'delivered' === $step['key'] ) {
					$date = $order->get_date_completed();
				}
				if ( $date ) {
					list( $sub, $sub2 ) = self::step_date( $date );
				} else {
					$sub = __( 'Terminé', 'delicat-builder-v9' );
				}
			} elseif ( in_array( $step['state'], array( 'current', 'alert' ), true ) ) {
				$sub = '' !== $step['subtitle'] ? $step['subtitle'] : __( 'En cours…', 'delicat-builder-v9' );
			} elseif ( 'ended' === $step['state'] ) {
				$sub = __( 'Arrêté', 'delicat-builder-v9' );
			} else {
				$sub = __( 'En attente', 'delicat-builder-v9' );
			}
			$glyph = $glyphs[ $step['key'] ] ?? 'info';
			if ( 'done' === $step['state'] ) {
				$glyph = 'check';
			} elseif ( 'alert' === $step['state'] ) {
				$glyph = 'priority';
			} elseif ( 'ended' === $step['state'] ) {
				$glyph = 'refunded' === $state['phase'] ? 'undo' : 'close';
			}
			$steps[] = array(
				'key'   => $step['key'],
				'state' => 'skipped' === $step['state'] ? 'todo' : $step['state'],
				'title' => $step['title'],
				'short' => $step['short'],
				'sub'   => Delicat_Builder_V9_Order_Status::fr( $sub ),
				'sub2'  => $sub2,
				'glyph' => $glyph,
				'in'    => $i > 0 && $reached[ $i ],
				'out'   => isset( $reached[ $i + 1 ] ) && $reached[ $i + 1 ],
			);
		}
		return $steps;
	}

	/**
	 * Payment card: method, when it was paid, reference, and a chip.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state state().
	 */
	public static function payment( $order, array $state ): array {
		$phase  = $state['phase'];
		$label  = Delicat_Builder_V9_Order_Status::payment_label( $order );
		$method = strtolower( (string) $order->get_payment_method() . ' ' . $label );
		$glyph  = 'card';
		if ( false !== strpos( $method, 'crédit boutique' ) || false !== strpos( $method, 'wallet' ) ) {
			$glyph = 'wallet';
		} elseif ( false !== strpos( $method, 'moncash' ) || false !== strpos( $method, 'natcash' ) ) {
			$glyph = 'phone-pay';
		}
		$paid_at = $order->get_date_paid();
		if ( 'refunded' === $phase ) {
			$chip = array( 'label' => __( 'Remboursé', 'delicat-builder-v9' ), 'tone' => 'neutral', 'glyph' => 'undo' );
		} elseif ( 'failed' === $phase ) {
			$chip = array( 'label' => __( 'Échoué', 'delicat-builder-v9' ), 'tone' => 'danger', 'glyph' => 'error' );
		} elseif ( ! empty( $state['paid'] ) ) {
			$chip = array( 'label' => __( 'Payé', 'delicat-builder-v9' ), 'tone' => 'success', 'glyph' => 'check-circle' );
		} elseif ( 'payment_review' === $phase ) {
			$chip = array( 'label' => __( 'En vérification', 'delicat-builder-v9' ), 'tone' => 'warning', 'glyph' => 'hourglass' );
		} elseif ( 'cancelled' === $phase ) {
			$chip = array( 'label' => __( 'Annulé', 'delicat-builder-v9' ), 'tone' => 'neutral', 'glyph' => 'cancel' );
		} elseif ( (float) $order->get_total() <= 0 ) {
			$chip = array( 'label' => __( 'Gratuit', 'delicat-builder-v9' ), 'tone' => 'success', 'glyph' => 'check-circle' );
		} else {
			$chip = array( 'label' => __( 'À payer', 'delicat-builder-v9' ), 'tone' => 'warning', 'glyph' => 'schedule' );
		}
		$when = '';
		if ( ! empty( $state['paid'] ) && $paid_at ) {
			/* translators: %s: date */
			$when = sprintf( __( 'Payé le %s', 'delicat-builder-v9' ), self::long_date( $paid_at ) );
		} elseif ( ! empty( $state['paid'] ) ) {
			$when = __( 'Paiement reçu', 'delicat-builder-v9' );
		} elseif ( 'payment_review' === $phase ) {
			$when = __( 'Nous confirmons votre paiement', 'delicat-builder-v9' );
		} elseif ( 'awaiting_payment' === $phase ) {
			$when = __( 'Paiement en attente', 'delicat-builder-v9' );
		}
		$ref = is_callable( array( $order, 'get_transaction_id' ) ) ? trim( wp_strip_all_tags( (string) $order->get_transaction_id() ) ) : '';
		return array(
			'label' => $label,
			'glyph' => $glyph,
			'when'  => $when,
			'ref'   => '' !== $ref ? Delicat_Builder_V9_Order_Status::cut( $ref, 32 ) : '',
			'chip'  => $chip,
		);
	}

	/**
	 * Contact card (the order's billing name, phone and e-mail) — this page is only ever
	 * shown to the signed-in owner (or a shop manager).
	 *
	 * @param WC_Order $order Order.
	 */
	public static function contact( $order ): array {
		return array_filter(
			array(
				'name'  => trim( wp_strip_all_tags( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
				'phone' => trim( wp_strip_all_tags( (string) $order->get_billing_phone() ) ),
				'email' => sanitize_email( (string) $order->get_billing_email() ),
			)
		);
	}

	/**
	 * Notes the shop sent to the customer (WooCommerce « customer notes »), newest first.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{date:string,html:string}>
	 */
	public static function updates( $order ): array {
		$notes = is_callable( array( $order, 'get_customer_order_notes' ) ) ? (array) $order->get_customer_order_notes() : array();
		$out   = array();
		foreach ( $notes as $note ) {
			if ( ! is_object( $note ) || ! isset( $note->comment_content ) ) {
				continue;
			}
			$gmt   = isset( $note->comment_date_gmt ) ? (string) $note->comment_date_gmt : '';
			$ts    = '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ? strtotime( $gmt . ' UTC' ) : false;
			$out[] = array(
				'date' => $ts ? self::long_date( new DateTime( '@' . $ts ) ) : '',
				'html' => wpautop( wptexturize( wp_kses_post( (string) $note->comment_content ) ) ),
			);
		}
		return $out;
	}

	/**
	 * WooCommerce's « Commander à nouveau »: same statuses, same nonce URL as its own button.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function order_again_url( $order ): string {
		if ( ! is_user_logged_in() || (int) $order->get_customer_id() !== get_current_user_id() || ! function_exists( 'wc_get_cart_url' ) ) {
			return '';
		}
		$statuses = (array) apply_filters( 'woocommerce_valid_order_statuses_for_order_again', array( 'completed' ) );
		if ( ! is_callable( array( $order, 'has_status' ) ) || ! $order->has_status( $statuses ) ) {
			return '';
		}
		return (string) wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' );
	}

	/**
	 * Hooks other plugins print on WooCommerce's order page (woocommerce_view_order), without
	 * WooCommerce's own details table, which this page replaces.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function extra_html( int $order_id ): string {
		$priority = has_action( 'woocommerce_view_order', 'woocommerce_order_details_table' );
		if ( false !== $priority ) {
			remove_action( 'woocommerce_view_order', 'woocommerce_order_details_table', $priority );
		}
		ob_start();
		do_action( 'woocommerce_view_order', $order_id );
		$html = trim( (string) ob_get_clean() );
		if ( false !== $priority ) {
			add_action( 'woocommerce_view_order', 'woocommerce_order_details_table', $priority );
		}
		return $html;
	}

	/**
	 * 9.5.2: the shareable receipt of this order for its signed-in owner, in the paid phases only
	 * and while « Partage du reçu » is on (same rule as the « Commande reçue » page). Built from the
	 * receipt model: never any code, never billing details.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state state().
	 * @return array{receipt:array,qr_svg:string}|null
	 */
	public static function receipt( $order, array $state ) {
		$settings = Delicat_Builder_V9_Order_Confirmation::settings();
		$owner    = (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === get_current_user_id();
		if ( empty( $settings['oc_share'] ) || empty( $state['shareable'] ) || ! $owner ) {
			return null;
		}
		$receipt = Delicat_Builder_V9_Order_Status::receipt( $order, $state );
		$qr_svg  = '';
		if ( ! empty( $receipt['link'] ) && class_exists( 'Delicat_Builder_V9_Qr', false ) ) {
			$matrix = Delicat_Builder_V9_Qr::matrix( (string) $receipt['link'] );
			$qr_svg = is_array( $matrix ) ? (string) Delicat_Builder_V9_Qr::svg( $matrix, 96 ) : '';
		}
		return array(
			'receipt' => $receipt,
			'qr_svg'  => $qr_svg,
		);
	}

	/**
	 * Everything the template prints (escaped by the template).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state state().
	 */
	public static function view( $order, array $state ): array {
		$base  = Delicat_Builder_V9_Order_Confirmation::view( $order, $state );
		$count = 0;
		foreach ( $base['lines'] as $line ) {
			$count += (int) $line['qty'];
		}
		$rows = Delicat_Builder_V9_Order_Status::amount_rows( $order );
		if ( isset( $rows[0] ) ) {
			/* translators: 1: label, 2: number of items */
			$rows[0]['label'] = sprintf( _n( '%1$s (%2$d article)', '%1$s (%2$d articles)', $count, 'delicat-builder-v9' ), $rows[0]['label'], $count );
		}
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['minus'] = 0 === strpos( (string) $row['value'], '-' );
		}
		$config          = Delicat_Builder_V9_Order_Confirmation::config( $order, $state );
		$config['owner'] = (int) $order->get_customer_id() === get_current_user_id();
		$share           = self::receipt( $order, $state );
		return array(
			'number'      => $base['number'],
			'placed'      => self::long_date( $order->get_date_created() ),
			'lines'       => $base['lines'],
			'count'       => $count,
			'rows'        => $rows,
			'total'       => $base['total'],
			'total_label' => $base['total_label'],
			'pay_url'     => in_array( $state['cta'], array( 'pay', 'retry' ), true ) ? $base['pay_url'] : '',
			'support_url' => $base['support_url'],
			'support_wa'  => $base['support_wa'],
			'again_url'   => self::order_again_url( $order ),
			'orders_url'  => $base['account_url'],
			'contact'     => self::contact( $order ),
			'player'      => $base['player'],
			'payment'     => self::payment( $order, $state ),
			'steps'       => self::steps( $order, $state ),
			'updates'     => self::updates( $order ),
			'note'        => trim( wp_strip_all_tags( (string) $order->get_customer_note() ) ),
			'glyph'       => self::phase_glyph( $state['phase'] ),
			'scene'       => self::scene( $state['phase'] ),
			'live'        => ! empty( $config['live'] ),
			'share'       => $share,
			'config'      => array(
				'endpoint'    => $config['endpoint'],
				'nonce'       => $config['nonce'],
				'order'       => $config['order'],
				'token'       => $config['token'],
				'fingerprint' => $config['fingerprint'],
				'live'        => ! empty( $config['live'] ),
				'maxMinutes'  => $config['maxMinutes'],
				'receiptJs'   => $share ? (string) $config['receiptJs'] : '',
				'motion'      => (string) ( Delicat_Builder_V9_Order_Confirmation::settings()['oc_motion'] ?? 'full' ),
				'receipt'     => $share ? Delicat_Builder_V9_Order_Confirmation::i18n() : array(),
				'i18n'        => array(
					'copy'     => __( 'Copier le code', 'delicat-builder-v9' ),
					'copied'   => __( 'Copié', 'delicat-builder-v9' ),
					'copyFail' => Delicat_Builder_V9_Order_Status::fr( __( 'Copie impossible : sélectionnez le code.', 'delicat-builder-v9' ) ),
					'live'     => __( 'Mise à jour automatique', 'delicat-builder-v9' ),
					'paused'   => __( 'Suivi en pause', 'delicat-builder-v9' ),
					'updated'  => __( 'Statut mis à jour', 'delicat-builder-v9' ),
				),
			),
		);
	}
}
