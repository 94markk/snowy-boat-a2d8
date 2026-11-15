<?php
/**
 * Order confirmation (Builder 9.4.0): the one status model.
 *
 * The hero, the 4-step tracker, the status pill, the shareable receipt and the live
 * endpoint all read state(); nothing else works a status out. Pure logic: no hook is
 * registered here, so tests/order_confirmation_smoke.php can drive it with stub orders.
 *
 * Phases (§2 of DESIGN-ORDER-CONFIRMATION-9.4.md): awaiting_payment, payment_review, paid,
 * sending, partial, review, delivered, failed, cancelled, refunded. Whether the money was
 * taken decides the wording, not the WooCommerce status alone: on this store « on-hold »
 * also comes after payment (supplier review hold), and a failed order that was charged is
 * never asked to pay again.
 *
 * Never in any output of this class: delivery codes, vouchers, serials, PINs, any _dfr_* or
 * _dst2t_* value, billing name / e-mail / phone / address, the order key, the customer note.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status, copy, money and receipt model for one WooCommerce order.
 */
final class Delicat_Builder_V9_Order_Status {

	/** Phase => tone. */
	public const PHASES = array(
		'awaiting_payment' => 'warning',
		'payment_review'   => 'warning',
		'paid'             => 'accent',
		'sending'          => 'accent',
		'partial'          => 'accent',
		'review'           => 'warning',
		'delivered'        => 'success',
		'failed'           => 'danger',
		'cancelled'        => 'neutral',
		'refunded'         => 'neutral',
	);

	/** Phases that stop live polling. */
	public const TERMINAL = array( 'delivered', 'failed', 'cancelled', 'refunded' );

	/**
	 * Phases with a receipt to share (« Partager le reçu », share sheet, print sheet): the money
	 * was taken and the order still stands. Never while a payment is due or checked, after a
	 * failed payment, or once the order is cancelled or refunded.
	 */
	public const SHARE_PHASES = array( 'paid', 'sending', 'partial', 'delivered', 'review' );

	/** Tracker node keys (the app's keys). */
	public const STEP_KEYS = array( 'received', 'paid', 'preparing', 'delivered' );

	/** Labels that are never a player field (codes, secrets). */
	private const SECRET_WORDS = array( 'code', 'pin', 'key', 'cle', 'cvv' );

	/** …and never as part of a word either. */
	private const SECRET_STEMS = array( 'serial', 'voucher', 'password', 'mot de passe', 'motdepasse', 'secret', 'token' );

	/** Receipt keys and types; a filter cannot add keys (sanitize_receipt()). */
	private const RECEIPT_SCHEMA = array(
		'v'           => 'int',
		'brand'       => array(
			'name' => 'str',
			'host' => 'str',
			'logo' => 'url',
		),
		'number'      => 'str',
		'date'        => 'str',
		'payment'     => 'str',
		'payment_ref' => 'ref',
		'status'      => 'str',
		'pill'        => array(
			'label' => 'str',
			'tone'  => 'key',
		),
		'lines'       => array(
			'*' => array(
				'qty'    => 'int',
				'name'   => 'str',
				'player' => 'str',
				'total'  => 'str',
			),
		),
		'more'        => 'int',
		'rows'        => array(
			'*' => array(
				'label' => 'str',
				'value' => 'str',
			),
		),
		'total'       => array(
			'label' => 'str',
			'value' => 'str',
		),
		'qr'          => array(
			'n'    => 'int',
			'rows' => array( '*' => 'hex' ),
		),
		'link'        => 'url',
		'footer'      => array( '*' => 'str' ),
		'file'        => 'file',
		'share_text'  => 'str',
	);

	/** @var array<string, array> Memoised states (order id, modification time, status, context). */
	private static $memo = array();

	/** Forget memoised states (tests, or after an order changed in the same request). */
	public static function reset(): void {
		self::$memo = array();
	}

	// ------------------------------------------------------------------
	// State
	// ------------------------------------------------------------------

	/**
	 * The shared state object (§2.5).
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $context 'page', 'rest', 'receipt' or 'legacy'.
	 * @return array
	 */
	public static function state( $order, string $context = 'page' ): array {
		$modified = is_callable( array( $order, 'get_date_modified' ) ) ? $order->get_date_modified() : null;
		$memo_key = $order->get_id() . '|' . ( $modified ? $modified->getTimestamp() : 0 ) . '|' . $order->get_status() . '|' . $context;
		if ( isset( self::$memo[ $memo_key ] ) ) {
			return self::$memo[ $memo_key ];
		}

		$facts = self::facts( $order );
		$phase = self::phase( $order, $facts, $context );
		$steps = self::steps( $order, $phase, $facts );
		$copy  = self::copy( $order, $phase, $facts );

		$leading = 0;
		foreach ( $steps as $step ) {
			if ( 'done' !== $step['state'] ) {
				break;
			}
			++$leading;
		}

		/* The hero action slot: pay / retry while a payment is due, support where the customer can
		 * only wait or ask (review, payment check, cancelled, refunded); the page swaps in
		 * « Partager le reçu » for « none » in the paid phases (shareable). */
		$cta = 'none';
		if ( in_array( $phase, array( 'review', 'payment_review', 'cancelled', 'refunded' ), true ) ) {
			$cta = 'support';
		} elseif ( ! $facts['paid'] && in_array( $phase, array( 'awaiting_payment', 'failed' ), true ) && self::needs_payment( $order ) ) {
			$cta = 'failed' === $phase ? 'retry' : 'pay';
		}

		$first = (string) $order->get_billing_first_name();
		$first = trim( wp_strip_all_tags( $first ) );
		$greet = '';
		if ( ! in_array( $phase, array( 'failed', 'cancelled', 'refunded' ), true ) ) {
			$greet = '' !== $first
				/* translators: %s: customer first name */
				? sprintf( __( 'Merci, %s !', 'delicat-builder-v9' ), self::cut( $first, 40 ) )
				: __( 'Merci pour votre commande !', 'delicat-builder-v9' );
		}

		$rows = array();
		foreach ( $facts['lines'] as $line ) {
			$rows[] = $line['state'];
		}

		$state = array(
			'phase'       => $phase,
			'tone'        => self::PHASES[ $phase ],
			'terminal'    => in_array( $phase, self::TERMINAL, true ),
			'group'       => self::group( $phase ),
			'pill'        => array(
				'label' => $copy['pill'],
				'tone'  => self::PHASES[ $phase ],
			),
			'greeting'    => self::fr( $greet ),
			'headline'    => $copy['headline'],
			'message'     => $copy['message'],
			'steps'       => $steps,
			'progress'    => round( max( 0, $leading - 1 ) / 3, 3 ),
			'delivery'    => array(
				'lines_total'         => $facts['lines_total'],
				'lines_done'          => $facts['lines_done'],
				'codes_available'     => $facts['codes'],
				'has_code_line'       => $facts['has_code_line'],
				'can_contact_support' => in_array( $phase, array( 'review', 'partial' ), true ) || 'waiting_code' === $facts['g_state'],
				'rows'                => $rows,
			),
			'cta'         => $cta,
			'paid'        => $facts['paid'],
			'shareable'   => $facts['paid'] && in_array( $phase, self::SHARE_PHASES, true ),
			'total_label' => $facts['paid'] ? __( 'Total payé', 'delicat-builder-v9' ) : __( 'Total', 'delicat-builder-v9' ),
		);
		$parts = array( $phase, $copy['headline'], $copy['message'], $copy['pill'], $facts['lines_done'], $facts['codes'], implode( ',', $rows ) );
		foreach ( $steps as $step ) {
			$parts[] = $step['state'];
		}
		$state['fingerprint'] = substr( sha1( implode( '|', $parts ) ), 0, 12 );

		self::$memo[ $memo_key ] = $state;
		return $state;
	}

	/** Page group that decides which delivery-card content shows. */
	public static function group( string $phase ): string {
		if ( in_array( $phase, array( 'awaiting_payment', 'payment_review' ), true ) ) {
			return 'wait';
		}
		if ( in_array( $phase, array( 'paid', 'sending', 'partial' ), true ) ) {
			return 'rows';
		}
		if ( 'review' === $phase || 'delivered' === $phase ) {
			return 'review' === $phase ? 'review' : 'done';
		}
		return 'end';
	}

	/**
	 * Whether the customer's money was taken (copy of Delicat_App_Orders::payment_received(),
	 * App API 2.32): paid, a wallet debit that stands, or a payment date.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function payment_received( $order ): bool {
		if ( is_callable( array( $order, 'is_paid' ) ) && $order->is_paid() ) {
			return true;
		}
		$wallet = (string) $order->get_meta( '_delicat_wallet_state', true );
		if ( in_array( $wallet, array( 'compensated', 'debit_failed' ), true ) ) {
			$received = false;
		} else {
			$received = in_array( $wallet, array( 'debited', 'manual_review', 'completed' ), true )
				|| null !== $order->get_date_paid();
		}
		return (bool) apply_filters( 'delicat_app_order_payment_received', $received, $order );
	}

	/** Which plugin delivers a line: 's2t', 'gateway' or 'other'. */
	public static function line_owner( $item, bool $gateway_present ): string {
		if ( '' !== trim( (string) $item->get_meta( '_dst2t_status', true ) ) ) {
			return 's2t';
		}
		return $gateway_present ? 'gateway' : 'other';
	}

	/**
	 * Line state: waiting, moving, delivered, review or refunded.
	 *
	 * @param WC_Order_Item_Product $item    Line.
	 * @param string                $owner   line_owner().
	 * @param string                $status  WooCommerce status.
	 * @param bool                  $paid    payment_received().
	 * @param string                $g_state Gateway summary state ('' without gateway lines).
	 */
	public static function line_state( $item, string $owner, string $status, bool $paid, string $g_state = '' ): string {
		if ( 's2t' === $owner ) {
			$value = sanitize_key( (string) $item->get_meta( '_dst2t_status', true ) );
			if ( 'intent' === $value ) {
				return 'waiting';
			}
			if ( 'completed' === $value ) {
				return 'delivered';
			}
			if ( 'refunded' === $value ) {
				return 'refunded';
			}
			if ( in_array( $value, array( 'failed', 'submission_failed', 'price_blocked', 'voucher_missing', 'voucher_storage_error', 'manual_review' ), true ) ) {
				return 'review';
			}
			return 'moving';
		}
		if ( 'gateway' === $owner ) {
			$line = sanitize_key( (string) $item->get_meta( '_dfr_delivery_state', true ) );
			if ( 'refunded' === $line ) {
				return 'refunded';
			}
			if ( in_array( $line, array( 'manual', 'delivered' ), true ) || 'delivered' === $g_state || 'completed' === $status ) {
				return 'delivered';
			}
			if ( in_array( $line, array( 'failed', 'needs_support' ), true ) ) {
				return 'review';
			}
			if ( 'refunded' === $g_state ) {
				return 'refunded';
			}
			$remote = trim( (string) $item->get_meta( '_dfr_remote_order_id', true ) );
			if ( '' !== $remote || in_array( $line, array( 'supplier_processing', 'waiting_code' ), true ) ) {
				$remote_status = self::supplier_status( $item->get_meta( '_dfr_remote_status', true ) );
				if ( in_array( $remote_status, array( 'failed', 'refunded' ), true ) ) {
					return 'review';
				}
				if ( '' !== (string) $item->get_meta( '_dfr_codes_enc', true ) ) {
					return 'delivered';
				}
				/* The gateway's own rule: a completed supplier order is delivered when the line needs no code (top-up). */
				return '' !== $remote && 'completed' === $remote_status && ! self::gateway_needs_code( $item ) ? 'delivered' : 'moving';
			}
			return 'waiting';
		}
		if ( 'completed' === $status ) {
			return 'delivered';
		}
		if ( 'processing' === $status ) {
			return 'moving';
		}
		if ( 'on-hold' === $status && $paid ) {
			return 'review';
		}
		return 'refunded' === $status ? 'refunded' : 'waiting';
	}

	/**
	 * Supplier status as the Digital Gateway normalises it (normalize_supplier_status()):
	 * completed, failed, refunded or processing.
	 *
	 * @param mixed $status Stored _dfr_remote_status.
	 */
	public static function supplier_status( $status ): string {
		$status = sanitize_key( is_scalar( $status ) ? (string) $status : '' );
		if ( in_array( $status, array( 'completed', 'complete', 'success', 'succeeded' ), true ) ) {
			return 'completed';
		}
		if ( in_array( $status, array( 'failed', 'failure', 'error', 'cancelled', 'canceled' ), true ) ) {
			return 'failed';
		}
		return in_array( $status, array( 'refund', 'refunded' ), true ) ? 'refunded' : 'processing';
	}

	/**
	 * Whether a gateway line delivers a code (gift card, game key) rather than a top-up: the
	 * order-item snapshot _dfr_service_type first, then the product's mapping like the gateway.
	 * Unknown counts as a code line, so the page waits for the code envelope.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 */
	private static function gateway_needs_code( $item ): bool {
		$type = sanitize_key( (string) $item->get_meta( '_dfr_service_type', true ) );
		if ( '' === $type && function_exists( 'get_post_meta' ) && is_callable( array( $item, 'get_product' ) ) ) {
			$product = $item->get_product();
			if ( is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ) {
				$parent = is_callable( array( $product, 'get_parent_id' ) ) ? (int) $product->get_parent_id() : 0;
				$type   = $parent ? sanitize_key( (string) get_post_meta( (int) $product->get_id(), '_dfr_service_type', true ) ) : '';
				if ( ! in_array( $type, array( 'topup', 'giftcard', 'gamekey' ), true ) ) {
					$type = sanitize_key( (string) get_post_meta( $parent ? $parent : (int) $product->get_id(), '_dfr_service_type', true ) );
				}
			}
		}
		return 'topup' !== $type;
	}

	/** Everything phase(), steps() and copy() read, worked out once. */
	private static function facts( $order ): array {
		$status  = (string) $order->get_status();
		$paid    = self::payment_received( $order );
		$gateway = apply_filters( 'delicat_app_order_delivery', null, $order, 'summary' );
		$gateway = is_array( $gateway ) ? $gateway : null;
		$g_state = null !== $gateway && isset( $gateway['state'] ) && is_scalar( $gateway['state'] ) ? sanitize_key( (string) $gateway['state'] ) : '';
		$codes   = null !== $gateway ? absint( $gateway['codes_available'] ?? 0 ) : 0;

		$lines    = array();
		$has_code = $codes > 0;
		$visible  = $codes > 0; // Gateway: codes the current viewer may see right now.
		$topup    = true;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$owner = self::line_owner( $item, null !== $gateway );
			$state = self::line_state( $item, $owner, $status, $paid, $g_state );
			if ( 'completed' === $status && in_array( $state, array( 'waiting', 'moving' ), true ) ) {
				$state = 'delivered'; // The order was closed.
			}
			$voucher = 's2t' === $owner && 'yes' === (string) $item->get_meta( '_dst2t_returns_voucher', true );
			$code    = $voucher || ( 'gateway' === $owner && '' !== (string) $item->get_meta( '_dfr_codes_enc', true ) );
			if ( $code ) {
				$has_code = true;
			}
			/* Shop2TopUp prints vouchers on completed orders for the owner or the order key (this page). Presence only, never read. */
			if ( $voucher && 'completed' === $status && 'delivered' === $state && '' !== (string) $item->get_meta( '_dst2t_vouchers', true ) ) {
				$visible = true;
			}
			$fields = self::player_fields( $item );
			if ( $code || '' === $fields['player'] ) {
				$topup = false;
			}
			$lines[] = array(
				'id'    => (int) $item_id,
				'owner' => $owner,
				'state' => $state,
			);
		}
		$total = 0;
		$done  = 0;
		foreach ( $lines as $line ) {
			if ( 'refunded' === $line['state'] ) {
				continue;
			}
			++$total;
			if ( 'delivered' === $line['state'] ) {
				++$done;
			}
		}
		return array(
			'status'        => $status,
			'paid'          => $paid,
			'gateway'       => null !== $gateway,
			'g_state'       => $g_state,
			'codes'         => $codes,
			'lines'         => $lines,
			'has_code_line' => $has_code,
			'codes_visible' => $visible,
			'topup_only'    => $topup && ! $has_code && ! empty( $lines ),
			'lines_total'   => $total,
			'lines_done'    => $done,
		);
	}

	/** Phase algorithm (§2.2, first match wins). */
	private static function phase( $order, array $facts, string $context ): string {
		$status = $facts['status'];
		$paid   = $facts['paid'];
		if ( 'refunded' === $status ) {
			$phase = 'refunded';
		} elseif ( 'cancelled' === $status ) {
			$phase = 'cancelled';
		} elseif ( 'failed' === $status ) {
			$phase = $paid ? 'review' : 'failed';
		} elseif ( ! $paid ) {
			$phase = 'on-hold' === $status ? 'payment_review' : 'awaiting_payment';
		} else {
			$groups = array();
			if ( $facts['gateway'] ) {
				$map = array(
					'awaiting_payment'    => 'waiting',
					'queued'              => 'waiting',
					'supplier_processing' => 'moving',
					'waiting_code'        => 'moving',
					'partial'             => 'partial',
					'delivered'           => 'delivered',
					'needs_support'       => 'review',
					'refunded'            => 'refunded',
				);
				if ( isset( $map[ $facts['g_state'] ] ) ) {
					$groups[] = $map[ $facts['g_state'] ];
				}
			}
			foreach ( $facts['lines'] as $line ) {
				if ( 'gateway' !== $line['owner'] ) {
					$groups[] = $line['state'];
				}
			}
			if ( 'completed' === $status ) {
				foreach ( $groups as $i => $group ) {
					if ( 'waiting' === $group || 'moving' === $group ) {
						$groups[ $i ] = 'delivered';
					}
				}
			}
			$active = array_values( array_diff( $groups, array( 'refunded' ) ) );
			if ( empty( $groups ) ) {
				$phase = 'completed' === $status ? 'delivered' : ( 'on-hold' === $status ? 'review' : 'paid' );
			} elseif ( empty( $active ) ) {
				$phase = 'refunded';
			} elseif ( in_array( 'review', $active, true ) || 'on-hold' === $status ) {
				$phase = 'review';
			} elseif ( array( 'delivered' ) === array_values( array_unique( $active ) ) ) {
				$phase = 'delivered';
			} elseif ( in_array( 'delivered', $active, true ) || in_array( 'partial', $active, true ) ) {
				$phase = 'partial';
			} elseif ( in_array( 'moving', $active, true ) ) {
				$phase = 'sending';
			} else {
				$phase = 'paid';
			}
		}
		$filtered = apply_filters( 'delicat_builder_order_phase', $phase, $order, $context );
		return is_string( $filtered ) && isset( self::PHASES[ $filtered ] ) ? $filtered : $phase;
	}

	/**
	 * The four tracker nodes. By construction the sequence matches
	 * ^done+(current|alert|ended)?(todo|skipped)*$ and only « delivered » has four done.
	 */
	private static function steps( $order, string $phase, array $facts ): array {
		$table = array(
			'awaiting_payment' => array( 'done', 'current', 'todo', 'todo' ),
			'payment_review'   => array( 'done', 'alert', 'todo', 'todo' ),
			'paid'             => array( 'done', 'done', 'current', 'todo' ),
			'sending'          => array( 'done', 'done', 'current', 'todo' ),
			'partial'          => array( 'done', 'done', 'done', 'current' ),
			'review'           => array( 'done', 'done', 'alert', 'todo' ),
			'delivered'        => array( 'done', 'done', 'done', 'done' ),
			'failed'           => array( 'done', 'ended', 'skipped', 'skipped' ),
			'cancelled'        => $facts['paid'] ? array( 'done', 'done', 'ended', 'skipped' ) : array( 'done', 'ended', 'skipped', 'skipped' ),
			'refunded'         => $order->get_date_completed() ? array( 'done', 'done', 'done', 'ended' ) : array( 'done', 'done', 'ended', 'skipped' ),
		);
		$states = $table[ $phase ];
		$steps  = array();
		foreach ( self::STEP_KEYS as $i => $key ) {
			$steps[] = array(
				'key'      => $key,
				'state'    => $states[ $i ],
				'title'    => self::step_title( $key, $states[ $i ], $phase ),
				'short'    => self::step_short( $key, $states[ $i ], $phase ),
				'subtitle' => self::step_subtitle( $order, $key, $states[ $i ], $phase, $facts ),
			);
		}
		return $steps;
	}

	private static function step_title( string $key, string $state, string $phase ): string {
		$titles = array(
			'received'  => array( 'done' => __( 'Commande reçue', 'delicat-builder-v9' ) ),
			'paid'      => array(
				'todo'    => __( 'Paiement', 'delicat-builder-v9' ),
				'current' => __( 'Paiement en attente', 'delicat-builder-v9' ),
				'alert'   => __( 'Paiement en vérification', 'delicat-builder-v9' ),
				'done'    => __( 'Paiement confirmé', 'delicat-builder-v9' ),
				'ended'   => 'failed' === $phase ? __( 'Paiement échoué', 'delicat-builder-v9' ) : __( 'Commande annulée', 'delicat-builder-v9' ),
			),
			'preparing' => array(
				'todo'    => __( 'Préparation', 'delicat-builder-v9' ),
				'current' => 'sending' === $phase ? __( 'Envoi en cours', 'delicat-builder-v9' ) : __( 'Préparation', 'delicat-builder-v9' ),
				'alert'   => __( 'Livraison à vérifier', 'delicat-builder-v9' ),
				'done'    => __( 'Envoyée', 'delicat-builder-v9' ),
				'ended'   => 'refunded' === $phase ? __( 'Remboursée', 'delicat-builder-v9' ) : __( 'Commande annulée', 'delicat-builder-v9' ),
			),
			'delivered' => array(
				'todo'    => __( 'Livraison', 'delicat-builder-v9' ),
				'current' => __( 'Livraison partielle', 'delicat-builder-v9' ),
				'done'    => __( 'Livrée', 'delicat-builder-v9' ),
				'ended'   => __( 'Remboursée', 'delicat-builder-v9' ),
			),
		);
		$state = 'skipped' === $state ? 'todo' : $state;
		return (string) ( $titles[ $key ][ $state ] ?? $titles[ $key ]['todo'] ?? $titles[ $key ]['done'] );
	}

	private static function step_subtitle( $order, string $key, string $state, string $phase, array $facts ): string {
		if ( 'done' === $state ) {
			$date = null;
			if ( 'received' === $key ) {
				$date = $order->get_date_created();
			} elseif ( 'paid' === $key ) {
				$date = $order->get_date_paid() ? $order->get_date_paid() : ( $facts['paid'] ? $order->get_date_created() : null );
			} elseif ( 'delivered' === $key ) {
				$date = $order->get_date_completed();
			}
			return $date ? (string) wp_date( 'H:i', $date->getTimestamp() ) : '';
		}
		if ( 'current' === $state ) {
			if ( 'partial' === $phase && $facts['lines_done'] > 0 && $facts['lines_done'] < $facts['lines_total'] ) {
				/* translators: 1: delivered lines, 2: lines */
				return sprintf( __( '%1$d sur %2$d articles', 'delicat-builder-v9' ), $facts['lines_done'], $facts['lines_total'] );
			}
			return 'awaiting_payment' === $phase ? __( 'À finaliser', 'delicat-builder-v9' ) : __( 'En cours…', 'delicat-builder-v9' );
		}
		return ''; // Alert nodes: the hero already says it (« aucun nouveau paiement n’est nécessaire »).
	}

	/** One-word node label for screens under 360 px (the long title stays for screen readers). */
	private static function step_short( string $key, string $state, string $phase ): string {
		$short = array(
			'received'  => array( 'done' => __( 'Reçue', 'delicat-builder-v9' ) ),
			'paid'      => array(
				'todo'    => __( 'Paiement', 'delicat-builder-v9' ),
				'current' => __( 'À payer', 'delicat-builder-v9' ),
				'alert'   => __( 'Vérification', 'delicat-builder-v9' ),
				'done'    => __( 'Payée', 'delicat-builder-v9' ),
				'ended'   => 'failed' === $phase ? __( 'Échoué', 'delicat-builder-v9' ) : __( 'Annulée', 'delicat-builder-v9' ),
			),
			'preparing' => array(
				'todo'    => __( 'Envoi', 'delicat-builder-v9' ),
				'current' => 'sending' === $phase ? __( 'Envoi', 'delicat-builder-v9' ) : __( 'Préparation', 'delicat-builder-v9' ),
				'alert'   => __( 'À vérifier', 'delicat-builder-v9' ),
				'done'    => __( 'Envoyée', 'delicat-builder-v9' ),
				'ended'   => 'refunded' === $phase ? __( 'Remboursée', 'delicat-builder-v9' ) : __( 'Annulée', 'delicat-builder-v9' ),
			),
			'delivered' => array(
				'todo'    => __( 'Livraison', 'delicat-builder-v9' ),
				'current' => __( 'Partielle', 'delicat-builder-v9' ),
				'done'    => __( 'Livrée', 'delicat-builder-v9' ),
				'ended'   => __( 'Remboursée', 'delicat-builder-v9' ),
			),
		);
		$state = 'skipped' === $state ? 'todo' : $state;
		return (string) ( $short[ $key ][ $state ] ?? $short[ $key ]['todo'] ?? $short[ $key ]['done'] );
	}

	/** Pill, headline and message for a phase (§2.3), after the copy filter. */
	private static function copy( $order, string $phase, array $facts ): array {
		$rows = array(
			'awaiting_payment' => array( __( 'En attente de paiement', 'delicat-builder-v9' ), __( 'En attente de paiement', 'delicat-builder-v9' ), __( 'Finalisez le paiement pour lancer la livraison.', 'delicat-builder-v9' ) ),
			'payment_review'   => array( __( 'En vérification', 'delicat-builder-v9' ), __( 'Paiement en vérification', 'delicat-builder-v9' ), __( 'Nous confirmons votre paiement. Vous n’avez rien à faire : la livraison démarre dès la confirmation.', 'delicat-builder-v9' ) ),
			'paid'             => array( __( 'Payée', 'delicat-builder-v9' ), __( 'Paiement confirmé', 'delicat-builder-v9' ), __( 'Préparation de votre livraison…', 'delicat-builder-v9' ) ),
			'sending'          => array( __( 'En cours de livraison', 'delicat-builder-v9' ), __( 'Livraison en cours', 'delicat-builder-v9' ), __( 'Votre commande est en route, généralement en moins d’une minute.', 'delicat-builder-v9' ) ),
			'partial'          => array( __( 'Partiellement livrée', 'delicat-builder-v9' ), __( 'Partiellement livrée', 'delicat-builder-v9' ), __( 'Une partie de votre commande est livrée. Le reste arrive.', 'delicat-builder-v9' ) ),
			'review'           => array( __( 'En vérification', 'delicat-builder-v9' ), __( 'Commande en vérification', 'delicat-builder-v9' ), __( 'Notre équipe vérifie votre commande ; aucun nouveau paiement n’est nécessaire.', 'delicat-builder-v9' ) ),
			'delivered'        => array( __( 'Livrée', 'delicat-builder-v9' ), __( 'Commande livrée', 'delicat-builder-v9' ), __( 'Votre commande est livrée.', 'delicat-builder-v9' ) ),
			'failed'           => array( __( 'Échouée', 'delicat-builder-v9' ), __( 'Paiement échoué', 'delicat-builder-v9' ), __( 'Le paiement n’a pas abouti. Vous pouvez réessayer.', 'delicat-builder-v9' ) ),
			'cancelled'        => array( __( 'Annulée', 'delicat-builder-v9' ), __( 'Commande annulée', 'delicat-builder-v9' ), __( 'Cette commande a été annulée. Contactez le support pour toute question.', 'delicat-builder-v9' ) ),
			'refunded'         => array( __( 'Remboursée', 'delicat-builder-v9' ), __( 'Commande remboursée', 'delicat-builder-v9' ), __( 'Cette commande a été remboursée.', 'delicat-builder-v9' ) ),
		);
		list( $pill, $headline, $message ) = $rows[ $phase ];
		if ( 'sending' === $phase && 'waiting_code' === $facts['g_state'] ) {
			$message = __( 'Transaction confirmée — récupération sécurisée de votre code.', 'delicat-builder-v9' );
		} elseif ( 'partial' === $phase && $facts['lines_done'] > 0 && $facts['lines_done'] < $facts['lines_total'] ) {
			/* translators: 1: delivered lines, 2: lines */
			$message = sprintf( __( '%1$d sur %2$d articles livrés. Le reste arrive.', 'delicat-builder-v9' ), $facts['lines_done'], $facts['lines_total'] );
		} elseif ( 'delivered' === $phase && $facts['codes_visible'] ) {
			/* Only when a code really prints below (the gateway's own rule: codes_available > 0). */
			$message = __( 'Votre code est disponible ci-dessous.', 'delicat-builder-v9' );
		} elseif ( 'delivered' === $phase && $facts['topup_only'] ) {
			$message = __( 'Recharge livrée sur votre compte de jeu.', 'delicat-builder-v9' );
		} elseif ( 'delivered' === $phase && is_object( $order ) && is_callable( array( $order, 'get_items' ) ) ) {
			/* 9.4.2: name what was delivered instead of repeating the headline. */
			$names = array();
			foreach ( $order->get_items() as $item ) {
				$names[] = is_callable( array( $item, 'get_name' ) ) ? trim( wp_strip_all_tags( (string) $item->get_name() ) ) : '';
			}
			$names = array_values( array_filter( $names ) );
			if ( 1 === count( $names ) ) {
				/* translators: %s: product name */
				$message = self::fr( sprintf( __( '« %s » est livré. Merci pour votre confiance !', 'delicat-builder-v9' ), self::cut( $names[0], 60 ) ) );
			} elseif ( count( $names ) > 1 ) {
				/* translators: %d: number of items */
				$message = self::fr( sprintf( __( 'Vos %d articles sont livrés. Merci pour votre confiance !', 'delicat-builder-v9' ), count( $names ) ) );
			}
		}
		$copy     = array(
			'pill'     => $pill,
			'headline' => $headline,
			'message'  => $message,
		);
		$filtered = apply_filters( 'delicat_builder_order_copy', $copy, $phase, $order );
		$caps     = array(
			'pill'     => 40,
			'headline' => 80,
			'message'  => 240,
		);
		foreach ( $caps as $field => $cap ) {
			if ( is_array( $filtered ) && isset( $filtered[ $field ] ) && is_string( $filtered[ $field ] ) && '' !== trim( wp_strip_all_tags( $filtered[ $field ] ) ) ) {
				$copy[ $field ] = $filtered[ $field ];
			}
			$copy[ $field ] = self::fr( self::cut( wp_strip_all_tags( (string) $copy[ $field ] ), $cap ) );
		}
		return $copy;
	}

	private static function needs_payment( $order ): bool {
		return is_callable( array( $order, 'needs_payment' ) ) && (bool) $order->needs_payment();
	}

	// ------------------------------------------------------------------
	// Text, money, dates
	// ------------------------------------------------------------------

	/** French spacing: a no-break space before « : ! ? ; » that follow a space. */
	public static function fr( string $text ): string {
		return (string) preg_replace( '/ ([!?:;])/u', "\u{00A0}$1", $text );
	}

	/** Trim to $max characters (multibyte safe), with an ellipsis when cut. */
	public static function cut( string $text, int $max ): string {
		/* ASCII whitespace only: the U+00A0 / U+202F of money() and fr() must survive. */
		$text = trim( (string) preg_replace( '/[ \t\r\n]+/', ' ', $text ), " \t\r\n" );
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $max ? rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . '…' : $text;
		}
		return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max - 1 ) ) . '…' : $text;
	}

	/**
	 * « 143 HTG », « 1 250,50 HTG », « -10 HTG »: U+202F groups, comma decimals dropped on
	 * whole amounts, U+00A0 before the code. Plain text; the order's own currency.
	 *
	 * @param float|int|string $amount   Amount.
	 * @param string           $currency ISO code ('' = store currency).
	 */
	public static function money( $amount, string $currency = '' ): string {
		$amount   = round( (float) $amount, 2 );
		$negative = $amount < 0 && abs( $amount ) >= 0.005;
		$fixed    = number_format( abs( $amount ), 2, '.', '' );
		list( $whole, $cents ) = explode( '.', $fixed );
		$groups = array();
		while ( strlen( $whole ) > 3 ) {
			array_unshift( $groups, substr( $whole, -3 ) );
			$whole = substr( $whole, 0, -3 );
		}
		array_unshift( $groups, $whole );
		$number   = implode( "\u{202F}", $groups ) . ( '00' === $cents ? '' : ',' . $cents );
		$currency = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', $currency ) );
		if ( '' === $currency ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'HTG';
		}
		return ( $negative ? '-' : '' ) . $number . "\u{00A0}" . $currency;
	}

	/** money() in a span that never wraps. */
	public static function money_html( $amount, string $currency = '' ): string {
		return '<span class="dlc-oc-money">' . esc_html( self::money( $amount, $currency ) ) . '</span>';
	}

	/** The order's currency code. */
	public static function currency( $order ): string {
		$code = is_callable( array( $order, 'get_currency' ) ) ? (string) $order->get_currency() : '';
		return '' !== $code ? strtoupper( $code ) : ( function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : 'HTG' );
	}

	/** « 23 oct. 2026 · 15:26 » in the site timezone (the app's receipt format). */
	public static function date_label( $date ): string {
		if ( ! is_object( $date ) || ! is_callable( array( $date, 'getTimestamp' ) ) ) {
			return '';
		}
		$months = array( '', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.' );
		$ts     = (int) $date->getTimestamp();
		$month  = (int) wp_date( 'n', $ts );
		/* U+00A0 before « · »: a line may break after the dot, never before it. */
		return wp_date( 'j', $ts ) . ' ' . ( $months[ $month ] ?? '' ) . ' ' . wp_date( 'Y', $ts ) . "\u{00A0}· " . wp_date( 'H:i', $ts );
	}

	/**
	 * Customer-facing payment label (§6.2). Wallet payments always read « Crédit boutique
	 * Délicat »; MonCash and Natcash keep their names; no wallet / portefeuille word ever.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function payment_label( $order ): string {
		$method = strtolower( (string) $order->get_payment_method() );
		$title  = trim( wp_strip_all_tags( (string) $order->get_payment_method_title() ) );
		$label  = self::label_rules( $method, $title, (float) $order->get_total() );
		$filter = apply_filters( 'delicat_builder_order_payment_label', $label, $method, $order );
		if ( is_string( $filter ) && '' !== trim( wp_strip_all_tags( $filter ) ) && $filter !== $label ) {
			$label = self::label_rules( '', trim( wp_strip_all_tags( $filter ) ), 1.0 );
		}
		return $label;
	}

	private static function label_rules( string $method, string $title, float $total ): string {
		if ( in_array( $method, array( 'wallet', 'delicat_wallet' ), true ) || preg_match( '/wallet|portefeuille/i', $title ) || preg_match( '/wallet/', $method ) ) {
			return __( 'Crédit boutique Délicat', 'delicat-builder-v9' );
		}
		$haystack = strtolower( $method . ' ' . $title );
		if ( false !== strpos( $haystack, 'moncash' ) ) {
			return 'MonCash';
		}
		if ( false !== strpos( $haystack, 'natcash' ) ) {
			return 'Natcash';
		}
		if ( '' === $method && '' === $title && $total <= 0 ) {
			return __( 'Aucun paiement requis', 'delicat-builder-v9' );
		}
		$app_view = class_exists( 'Delicat_Builder_V9_App_View', false ) && Delicat_Builder_V9_App_View::active();
		if ( '' === $title || ( $app_view && Delicat_Builder_V9_App_View::names_hidden( $title ) ) ) {
			return __( 'Paiement en ligne', 'delicat-builder-v9' );
		}
		return self::cut( $title, 40 );
	}

	/**
	 * Player fields safe to show (page chips and receipt): array( player, name, server ).
	 * From Delicat_App_Helpers::customer_order_item_fields() when the App API is there,
	 * plus an own allowlist. A label naming a code, PIN, serial, voucher, password, secret,
	 * key, token or CVV is always rejected; values must look like an ID.
	 *
	 * @param WC_Order_Item_Product $item Line.
	 * @return array{player:string,name:string,server:string}
	 */
	public static function player_fields( $item ): array {
		$out    = array(
			'player' => '',
			'name'   => '',
			'server' => '',
		);
		$fields = array();
		if ( class_exists( 'Delicat_App_Helpers', false ) && is_callable( array( 'Delicat_App_Helpers', 'customer_order_item_fields' ) ) ) {
			try {
				foreach ( (array) Delicat_App_Helpers::customer_order_item_fields( $item ) as $label => $value ) {
					$fields[] = array( (string) $label, $value );
				}
			} catch ( Throwable $error ) {
				unset( $error );
			}
		}
		if ( is_callable( array( $item, 'get_meta_data' ) ) ) {
			foreach ( (array) $item->get_meta_data() as $meta ) {
				$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
				if ( '' === $key ) {
					continue;
				}
				$lower = strtolower( $key );
				if ( '_' === substr( $key, 0, 1 ) && '_ftr_field_user_id' !== $lower ) {
					continue;
				}
				$fields[] = array( '_ftr_field_user_id' === $lower ? 'player_id' : $key, $meta->value ?? '' );
			}
		}
		foreach ( $fields as $pair ) {
			$norm  = self::normalise_label( $pair[0] );
			$value = is_scalar( $pair[1] ) ? trim( wp_strip_all_tags( (string) $pair[1] ) ) : '';
			if ( '' === $norm || '' === $value || self::label_is_secret( $norm ) || ! preg_match( '~^[A-Za-z0-9 ._:#/\-]{1,40}$~', $value ) ) {
				continue;
			}
			$words  = explode( ' ', $norm );
			$naming = in_array( 'nom', $words, true ) || in_array( 'name', $words, true ) || false !== strpos( $norm, 'username' );
			$player = false !== strpos( $norm, 'player' ) || false !== strpos( $norm, 'joueur' ) || in_array( $norm, array( 'uid', 'user id', 'player id', 'playerid' ), true );
			if ( $player && $naming ) {
				$out['name'] = '' === $out['name'] ? $value : $out['name'];
			} elseif ( $player ) {
				$out['player'] = '' === $out['player'] ? $value : $out['player'];
			} elseif ( in_array( $norm, array( 'server id', 'serverid', 'zone id', 'server', 'serveur', 'zone' ), true ) ) {
				$out['server'] = '' === $out['server'] ? $value : $out['server'];
			}
		}
		return $out;
	}

	private static function normalise_label( string $label ): string {
		$label = html_entity_decode( wp_strip_all_tags( $label ), ENT_QUOTES, 'UTF-8' );
		$label = strtolower( function_exists( 'remove_accents' ) ? remove_accents( $label ) : $label );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $label ) );
	}

	private static function label_is_secret( string $norm ): bool {
		$words = explode( ' ', $norm );
		foreach ( self::SECRET_WORDS as $word ) {
			if ( in_array( $word, $words, true ) ) {
				return true;
			}
		}
		foreach ( self::SECRET_STEMS as $stem ) {
			if ( false !== strpos( $norm, $stem ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Receipt
	// ------------------------------------------------------------------

	/** Amount rows under the lines (non-zero only). @return array<int, array{label:string,value:string}> */
	public static function amount_rows( $order ): array {
		$currency = self::currency( $order );
		$rows     = array(
			array(
				'label' => __( 'Sous-total', 'delicat-builder-v9' ),
				'value' => self::money( (float) $order->get_subtotal(), $currency ),
			),
		);
		$discount = (float) $order->get_total_discount();
		if ( $discount > 0 ) {
			$rows[] = array(
				'label' => __( 'Remise', 'delicat-builder-v9' ),
				'value' => self::money( -$discount, $currency ),
			);
		}
		$shipping = is_callable( array( $order, 'get_shipping_total' ) ) ? (float) $order->get_shipping_total() : 0.0;
		if ( $shipping > 0 ) {
			$rows[] = array(
				'label' => __( 'Frais d’envoi', 'delicat-builder-v9' ),
				'value' => self::money( $shipping, $currency ),
			);
		}
		foreach ( (array) $order->get_fees() as $fee ) {
			$amount = (float) $fee->get_total();
			if ( abs( $amount ) < 0.005 ) {
				continue;
			}
			$rows[] = array(
				'label' => self::cut( wp_strip_all_tags( (string) $fee->get_name() ), 40 ),
				'value' => self::money( $amount, $currency ),
			);
		}
		$tax = (float) $order->get_total_tax();
		if ( $tax > 0 ) {
			$rows[] = array(
				'label' => __( 'Taxe', 'delicat-builder-v9' ),
				'value' => self::money( $tax, $currency ),
			);
		}
		$refunded = (float) $order->get_total_refunded();
		if ( $refunded > 0 ) {
			$rows[] = array(
				'label' => __( 'Remboursé', 'delicat-builder-v9' ),
				'value' => self::money( -$refunded, $currency ),
			);
		}
		return $rows;
	}

	/** Line amount as shown on the page and the receipt (before order-level discounts). */
	public static function line_amount( $item ): float {
		$amount = (float) $item->get_subtotal();
		if ( function_exists( 'get_option' ) && 'incl' === get_option( 'woocommerce_tax_display_cart' ) ) {
			$amount += (float) $item->get_subtotal_tax();
		}
		return $amount;
	}

	/**
	 * Shareable receipt model (§5.2): built from an allowlist, never any code, never billing
	 * details. Guests (customer id 0) get no QR and no link: the order-received URL carries
	 * the order key, which must never be on a shared picture.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $state state().
	 */
	public static function receipt( $order, array $state ): array {
		$currency = self::currency( $order );
		$number   = (string) $order->get_order_number();
		$home     = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
		$host     = (string) wp_parse_url( $home, PHP_URL_HOST );
		$host     = (string) preg_replace( '/^www\./', '', $host );
		$logo     = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url( 96 ) : '';
		$logo_raw = (string) wp_parse_url( $logo, PHP_URL_HOST );
		if ( '' === $logo || ( '' !== $logo_raw && preg_replace( '/^www\./', '', $logo_raw ) !== $host ) ) {
			$logo = '';
		}

		$lines = array();
		$count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			++$count;
			if ( $count > 6 ) {
				continue;
			}
			$fields  = self::player_fields( $item );
			$lines[] = array(
				'qty'    => max( 1, (int) $item->get_quantity() ),
				'name'   => self::cut( wp_strip_all_tags( (string) $item->get_name() ), 120 ),
				'player' => $fields['player'],
				'total'  => self::money( self::line_amount( $item ), $currency ),
			);
		}

		$paid = ! empty( $state['paid'] );
		$tone = 'pending';
		if ( in_array( $state['phase'], array( 'cancelled', 'refunded' ), true ) ) {
			$tone = 'neutral';
		} elseif ( $paid && 'failed' !== $state['phase'] ) {
			$tone = 'paid';
		}

		$customer = (int) $order->get_customer_id();
		$link     = null;
		$qr       = null;
		if ( $customer > 0 && is_callable( array( $order, 'get_view_order_url' ) ) ) {
			$link = (string) $order->get_view_order_url();
			if ( class_exists( 'Delicat_Builder_V9_Qr', false ) ) {
				$matrix = Delicat_Builder_V9_Qr::matrix( $link );
				if ( is_array( $matrix ) ) {
					$qr = array(
						'n'    => count( $matrix ),
						'rows' => Delicat_Builder_V9_Qr::rows_hex( $matrix ),
					);
				}
			}
		}
		$reference = (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $order->get_transaction_id() );

		$receipt = array(
			'v'           => 1,
			'brand'       => array(
				'name' => 'DÉLICAT STORE',
				'host' => $host,
				'logo' => $logo,
			),
			'number'      => $number,
			'date'        => self::date_label( $order->get_date_created() ),
			'payment'     => self::payment_label( $order ),
			'payment_ref' => substr( $reference, 0, 32 ),
			'status'      => (string) $state['pill']['label'],
			'pill'        => array(
				'label' => (string) $state['pill']['label'],
				'tone'  => $tone,
			),
			'lines'       => $lines,
			'more'        => max( 0, $count - 6 ),
			'rows'        => self::amount_rows( $order ),
			'total'       => array(
				'label' => $paid ? __( 'TOTAL PAYÉ', 'delicat-builder-v9' ) : __( 'TOTAL', 'delicat-builder-v9' ),
				'value' => self::money( (float) $order->get_total(), $currency ),
			),
			'qr'          => $qr,
			'link'        => $link,
			'footer'      => array(
				self::fr( __( 'Merci pour votre achat !', 'delicat-builder-v9' ) ),
				$customer > 0
					/* translators: 1: order number, 2: site host */
					? sprintf( __( 'Reçu n° %1$s · %2$s', 'delicat-builder-v9' ), $number, $host )
					: __( 'Commande invitée — conservez l’e-mail de confirmation', 'delicat-builder-v9' ),
				__( 'Les codes ne figurent jamais sur ce reçu.', 'delicat-builder-v9' ),
			),
			'file'        => 'recu-delicat-' . preg_replace( '/[^A-Za-z0-9_-]/', '', $number ),
			/* translators: %s: order number */
			'share_text'  => sprintf( __( 'Reçu Délicat Store #%s', 'delicat-builder-v9' ), $number ),
		);

		$receipt = self::sanitize_receipt( apply_filters( 'delicat_builder_order_receipt', $receipt, $order ) );
		if ( '' === $receipt['payment'] || preg_match( '/wallet|portefeuille/i', $receipt['payment'] ) ) {
			$receipt['payment'] = self::payment_label( $order );
		}
		if ( $customer <= 0 ) {
			$receipt['qr']   = null;
			$receipt['link'] = null;
		}
		return $receipt;
	}

	/**
	 * Keep only the receipt keys and scalar types of RECEIPT_SCHEMA.
	 *
	 * @param mixed $receipt Filtered receipt.
	 */
	public static function sanitize_receipt( $receipt ): array {
		$clean = self::clean( is_array( $receipt ) ? $receipt : array(), self::RECEIPT_SCHEMA );
		foreach ( array( 'qr', 'link' ) as $nullable ) {
			if ( empty( $clean[ $nullable ] ) || ( 'qr' === $nullable && ( empty( $clean['qr']['rows'] ) || $clean['qr']['n'] < 21 ) ) ) {
				$clean[ $nullable ] = null;
			}
		}
		$clean['lines']  = array_slice( $clean['lines'], 0, 6 );
		$clean['footer'] = array_slice( $clean['footer'], 0, 3 );
		$clean['v']      = 1;
		return $clean;
	}

	/** Recursive allowlist walk used by sanitize_receipt(). */
	private static function clean( $value, $schema ) {
		if ( is_array( $schema ) ) {
			$value = is_array( $value ) ? $value : array();
			if ( isset( $schema['*'] ) ) {
				$out = array();
				foreach ( array_values( $value ) as $entry ) {
					$out[] = self::clean( $entry, $schema['*'] );
				}
				return $out;
			}
			$out = array();
			foreach ( $schema as $key => $type ) {
				$out[ $key ] = self::clean( $value[ $key ] ?? null, $type );
			}
			return $out;
		}
		switch ( $schema ) {
			case 'int':
				return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
			case 'url':
				$url = is_string( $value ) ? trim( $value ) : '';
				return preg_match( '~^https?://[^\s"<>]+$~i', $url ) ? substr( $url, 0, 300 ) : '';
			case 'hex':
				return is_string( $value ) && preg_match( '/^[0-9a-f]{1,32}$/', $value ) ? $value : '';
			case 'key':
				return is_string( $value ) ? substr( (string) preg_replace( '/[^a-z_-]/', '', strtolower( $value ) ), 0, 20 ) : '';
			case 'ref':
				return is_string( $value ) ? substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value ), 0, 32 ) : '';
			case 'file':
				$file = is_string( $value ) ? (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value ) : '';
				return '' !== $file ? substr( $file, 0, 60 ) : 'recu-delicat';
			default:
				return is_scalar( $value ) ? self::cut( wp_strip_all_tags( (string) $value ), 160 ) : '';
		}
	}
}
