<?php
/**
 * Bon Kliyan: the customer who ordered the most this week wins the weekly
 * prize. Cycle: Monday 00:00 → Sunday 23:59, Haiti time. Counted: completed
 * orders (paid AND delivered). Not counted: cancelled, refunded, unpaid.
 * Ties: the earlier first order wins. Rules page: legal/reglement-bon-kliyan.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Start and end of the current cycle. Pure.
 *
 * @param DateTimeImmutable|null $now Reference time (any zone).
 * @return array{0:DateTimeImmutable,1:DateTimeImmutable} Monday 00:00:00 and Sunday 23:59:59 in Haiti time.
 */
function ds_kliyan_window( ?DateTimeImmutable $now = null ): array {
	$zone = new DateTimeZone( 'America/Port-au-Prince' );
	$now  = ( $now ?? new DateTimeImmutable( 'now' ) )->setTimezone( $zone );
	$dow  = (int) $now->format( 'N' ); // 1 = Monday.
	$start = $now->setTime( 0, 0, 0 )->sub( new DateInterval( 'P' . ( $dow - 1 ) . 'D' ) );
	$end   = $start->add( new DateInterval( 'P6D' ) )->setTime( 23, 59, 59 );
	return array( $start, $end );
}

/**
 * Aggregate order rows into a ranked board. Pure.
 *
 * @param array<int,array{customer:string,name:string,total:float,created:int}> $rows One per order.
 * @param int                                                                    $size  Rows to keep.
 * @return array<int,array{customer:string,name:string,total:float,orders:int,first:int}>
 */
function ds_kliyan_rank( array $rows, int $size ): array {
	$by = array();
	foreach ( $rows as $row ) {
		$key = $row['customer'];
		if ( '' === $key ) {
			continue;
		}
		if ( ! isset( $by[ $key ] ) ) {
			$by[ $key ] = array(
				'customer' => $key,
				'name'     => $row['name'],
				'total'    => 0.0,
				'orders'   => 0,
				'first'    => $row['created'],
			);
		}
		$by[ $key ]['total']  += (float) $row['total'];
		$by[ $key ]['orders'] += 1;
		$by[ $key ]['first']   = min( $by[ $key ]['first'], $row['created'] );
	}
	$list = array_values( $by );
	usort(
		$list,
		static function ( array $a, array $b ): int {
			if ( $a['total'] !== $b['total'] ) {
				return $a['total'] < $b['total'] ? 1 : -1;
			}
			return $a['first'] <=> $b['first'];
		}
	);
	return array_slice( $list, 0, max( 1, $size ) );
}

/**
 * This week's board, cached 15 minutes and flushed when an order completes.
 *
 * @return array<int,array{customer:string,name:string,total:float,orders:int,first:int}>
 */
function ds_kliyan_board(): array {
	$size   = max( 1, min( 20, (int) ds_opt( 'kliyan_size' ) ) );
	$cached = get_transient( 'ds_kliyan_board' );
	if ( is_array( $cached ) && isset( $cached['size'] ) && $cached['size'] === $size ) {
		return $cached['board'];
	}
	list( $start, $end ) = ds_kliyan_window();
	$orders = wc_get_orders(
		array(
			'status'       => array( 'completed' ),
			'date_created' => $start->getTimestamp() . '...' . $end->getTimestamp(),
			'limit'        => 2000,
			'orderby'      => 'date',
			'order'        => 'ASC',
			'return'       => 'objects',
		)
	);
	$rows = array();
	foreach ( $orders as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}
		$user_id  = (int) $order->get_customer_id();
		$customer = $user_id > 0 ? 'u' . $user_id : 'e' . strtolower( trim( (string) $order->get_billing_email() ) );
		$name     = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name && $user_id > 0 ) {
			$user = get_userdata( $user_id );
			$name = $user ? (string) $user->display_name : '';
		}
		$created = $order->get_date_created();
		$rows[]  = array(
			'customer' => $customer,
			'name'     => $name,
			'total'    => (float) $order->get_total() - (float) $order->get_total_refunded(),
			'created'  => $created ? $created->getTimestamp() : 0,
		);
	}
	$board = ds_kliyan_rank( $rows, $size );
	set_transient(
		'ds_kliyan_board',
		array(
			'size'  => $size,
			'board' => $board,
		),
		15 * MINUTE_IN_SECONDS
	);
	return $board;
}

/**
 * Board markup. Used by the homepage section, the Bon Kliyan page template
 * and the [delicat_bon_kliyan] shortcode.
 *
 * @param bool $compact Homepage variant.
 */
function ds_kliyan_render( bool $compact = false ): string {
	$board  = ds_kliyan_board();
	list( $start, $end ) = ds_kliyan_window();
	$rules  = function_exists( 'ds_legal_url' ) ? ds_legal_url( 'contest' ) : '';
	$prize  = trim( (string) ds_opt( 'kliyan_prize' ) );
	$show_amounts = (int) ds_opt( 'kliyan_amounts' ) === 1;
	$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'G';

	$out  = '<div class="ds-kliyan' . ( $compact ? ' ds-kliyan--compact' : '' ) . '">';
	$out .= '<div class="ds-kliyan__head">' . ds_icon( 'trophy', 'ds-kliyan__icon' ) . '<div><p class="ds-kliyan__period">Semaine du ' . esc_html( date_i18n( 'j F', $start->getTimestamp() ) ) . ' au ' . esc_html( date_i18n( 'j F', $end->getTimestamp() ) ) . '</p>';
	if ( '' !== $prize ) {
		$out .= '<p class="ds-kliyan__prize">' . esc_html( $prize ) . '</p>';
	}
	$out .= '</div></div>';

	if ( array() === $board ) {
		$out .= '<p class="ds-kliyan__empty">Aucune commande terminée cette semaine pour le moment. La première place est à prendre.</p>';
	} else {
		$out .= '<ol class="ds-kliyan__list">';
		foreach ( $board as $i => $row ) {
			$rank = $i + 1;
			$out .= '<li class="ds-kliyan__row ds-kliyan__row--' . $rank . '">';
			$out .= '<span class="ds-kliyan__rank">' . ( 1 === $rank ? ds_icon( 'crown' ) : esc_html( (string) $rank ) ) . '</span>';
			$out .= '<span class="ds-kliyan__name">' . esc_html( ds_mask_name( $row['name'] ) ) . '<small>' . esc_html( sprintf( '%d commande%s', $row['orders'], $row['orders'] > 1 ? 's' : '' ) ) . '</small></span>';
			if ( $show_amounts ) {
				$out .= '<span class="ds-kliyan__total">' . wp_kses_post( wc_price( $row['total'] ) ) . '</span>';
			}
			$out .= '</li>';
		}
		$out .= '</ol>';
	}
	$out .= '<p class="ds-kliyan__foot">Classement sur les commandes payées et livrées, mis à jour toutes les 15 minutes.';
	if ( '' !== $rules ) {
		$out .= ' <a href="' . esc_url( $rules ) . '">Règlement du programme</a>.';
	}
	$out .= '</p></div>';
	unset( $symbol );
	return $out;
}

add_shortcode(
	'delicat_bon_kliyan',
	static function (): string {
		return ds_kliyan_render( false );
	}
);
