<?php
/**
 * « Commande reçue » page (Delicat Builder 9.4.0, module Confirmation de commande).
 * Replaces WooCommerce's checkout/thankyou.php while the module owns the page.
 *
 * Hook order is WooCommerce's: woocommerce_before_thankyou, woocommerce_thankyou_{method},
 * woocommerce_thankyou (the Digital Gateway's delivery trigger runs there at priority 5,
 * before the status is read), then one fresh copy of the order drives the whole page.
 * Codes only ever appear in the « Vos codes » card, rendered by the gateway and Shop2TopUp;
 * the shareable receipt and the print sheet never carry any.
 *
 * Icons: 24 px stroke icons drawn for this module.
 *
 * @package Delicat_Builder_V9
 * @var WC_Order|false $order Set by wc_get_template().
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Delicat_Builder_V9_Order_Confirmation', false ) || ! class_exists( 'Delicat_Builder_V9_Order_Status', false ) ) {
	/* Module not loaded on this request: WooCommerce's own template. */
	if ( function_exists( 'WC' ) && is_callable( array( WC(), 'plugin_path' ) ) && is_readable( WC()->plugin_path() . '/templates/checkout/thankyou.php' ) ) {
		include WC()->plugin_path() . '/templates/checkout/thankyou.php';
	}
	return;
}

$dlc_oc_icon = static function ( string $name, string $class = 'dlc-oc-i' ): string {
	return '<svg class="' . esc_attr( $class ) . '" aria-hidden="true" focusable="false"><use href="#dlc-oc-i-' . esc_attr( $name ) . '"></use></svg>';
};

$dlc_oc_order = isset( $order ) && is_object( $order ) && Delicat_Builder_V9_Order_Confirmation::can_view( $order ) ? $order : false;

if ( ! $dlc_oc_order ) :
	/* No order or no access: no data, and no thank-you hook (WooCommerce fires none here either). */
	?>
	<section class="dlc-oc dlc-oc--empty" aria-labelledby="dlc-oc-title">
		<div class="dlc-oc-card dlc-oc-hero" data-tone="neutral">
			<span class="dlc-oc-hero__bg" aria-hidden="true"></span>
			<h2 id="dlc-oc-title" class="dlc-oc-hero__title"><?php echo esc_html( Delicat_Builder_V9_Order_Status::fr( __( 'Merci pour votre commande !', 'delicat-builder-v9' ) ) ); ?></h2>
			<p class="dlc-oc-hero__msg"><?php esc_html_e( 'Retrouvez son suivi et vos codes dans vos commandes.', 'delicat-builder-v9' ); ?></p>
			<div class="dlc-oc-actions">
				<?php if ( function_exists( 'wc_get_account_endpoint_url' ) ) : ?>
					<a class="dlc-oc-btn dlc-oc-btn--primary" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php esc_html_e( 'Mes commandes', 'delicat-builder-v9' ); ?></a>
				<?php endif; ?>
				<a class="dlc-oc-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Accueil', 'delicat-builder-v9' ); ?></a>
			</div>
		</div>
	</section>
	<?php
	return;
endif;

$dlc_oc_id = (int) $dlc_oc_order->get_id();

ob_start();
do_action( 'woocommerce_before_thankyou', $dlc_oc_id );
$dlc_oc_before = trim( (string) ob_get_clean() );

ob_start();
do_action( 'woocommerce_thankyou_' . $dlc_oc_order->get_payment_method(), $dlc_oc_id );
$dlc_oc_method = trim( (string) ob_get_clean() );

ob_start();
do_action( 'woocommerce_thankyou', $dlc_oc_id );
$dlc_oc_thankyou = trim( (string) ob_get_clean() );

/* Fresh copy: the gateway trigger above may have moved the order on. */
$dlc_oc_fresh = wc_get_order( $dlc_oc_id );
if ( $dlc_oc_fresh ) {
	$dlc_oc_order = $dlc_oc_fresh;
}
Delicat_Builder_V9_Order_Status::reset();
$dlc_oc_state   = Delicat_Builder_V9_Order_Status::state( $dlc_oc_order );
$dlc_oc_view    = Delicat_Builder_V9_Order_Confirmation::view( $dlc_oc_order, $dlc_oc_state );
$dlc_oc_codes   = ( $dlc_oc_state['paid'] && 'end' !== $dlc_oc_state['group'] ) ? Delicat_Builder_V9_Order_Confirmation::render_codes_zone( $dlc_oc_order ) : '';
/* The receipt (and with it every share action, the share sheet and the print sheet) exists in
 * the paid phases only: never while a payment is due or checked, after a failed payment, or
 * once the order is cancelled or refunded (Delicat_Builder_V9_Order_Status::SHARE_PHASES). */
$dlc_oc_receipt = $dlc_oc_view['share'] ? Delicat_Builder_V9_Order_Status::receipt( $dlc_oc_order, $dlc_oc_state ) : null;
if ( $dlc_oc_receipt ) {
	Delicat_Builder_V9_Order_Confirmation::remember_receipt( $dlc_oc_receipt );
}
$dlc_oc_config = Delicat_Builder_V9_Order_Confirmation::config( $dlc_oc_order, $dlc_oc_state );
$dlc_oc_json   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$dlc_oc_emblems = array(
	'awaiting_payment' => 'clock',
	'payment_review'   => 'shield',
	'paid'             => 'box',
	'sending'          => 'box',
	'partial'          => 'box',
	'review'           => 'alert',
	'delivered'        => 'check',
	'failed'           => 'x',
	'cancelled'        => 'x',
	'refunded'         => 'return',
);
$dlc_oc_phase   = $dlc_oc_state['phase'];
$dlc_oc_now     = wp_date( 'H:i' );
$dlc_oc_live    = ! empty( $dlc_oc_config['live'] );
$dlc_oc_has_cta = in_array( $dlc_oc_state['cta'], array( 'pay', 'retry' ), true ) && '' !== $dlc_oc_view['pay_url'];
$dlc_oc_cta     = $dlc_oc_has_cta || 'support' === $dlc_oc_state['cta'] ? $dlc_oc_state['cta'] : 'none';
/* One button in the hero slot at a time: pay / retry / support, else « Partager le reçu » (paid phases). */
if ( 'none' === $dlc_oc_cta && $dlc_oc_receipt ) {
	$dlc_oc_cta = 'share';
}
/* Card entrance (A1): one 70 ms step per card actually shown, in page order. */
$dlc_oc_seq     = 0;
$dlc_oc_stagger = static function ( bool $shown = true ) use ( &$dlc_oc_seq ): string {
	if ( ! $shown ) {
		return '';
	}
	$dlc_oc_seq = min( 6, $dlc_oc_seq + 1 );
	return ' dlc-oc-s' . $dlc_oc_seq;
};
/* Bottom actions: « Partager le reçu » leads in the paid phases; otherwise « Mes commandes »
 * (signed in) or « Continuer mes achats » (guests) is the primary button. data-oc-next marks the
 * button that takes the lead if the order leaves the paid phases while the page is open. */
$dlc_oc_orders = '' !== $dlc_oc_view['orders_url'];
?>
<section class="dlc-oc" id="dlc-oc" data-phase="<?php echo esc_attr( $dlc_oc_phase ); ?>" data-group="<?php echo esc_attr( $dlc_oc_state['group'] ); ?>" data-cta="<?php echo esc_attr( $dlc_oc_cta ); ?>" data-share="<?php echo $dlc_oc_receipt ? '1' : '0'; ?>" data-motion="<?php echo esc_attr( $dlc_oc_view['motion'] ); ?>" data-entrance="<?php echo esc_attr( $dlc_oc_view['entrance'] ); ?>" data-live="<?php echo $dlc_oc_view['cta_reserve'] ? '1' : '0'; ?>" aria-labelledby="dlc-oc-title">
	<svg class="dlc-oc-sprite" xmlns="http://www.w3.org/2000/svg" hidden aria-hidden="true" focusable="false">
		<symbol id="dlc-oc-i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></symbol>
		<symbol id="dlc-oc-i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></symbol>
		<symbol id="dlc-oc-i-shield" viewBox="0 0 24 24"><path d="M12 3l7 3v5.5c0 4.3-3 7.9-7 9.5-4-1.6-7-5.2-7-9.5V6z"/><path d="M12 8.5V12l2.2 1.4"/></symbol>
		<symbol id="dlc-oc-i-box" viewBox="0 0 24 24"><path d="M4 8l8-4 8 4-8 4z"/><path d="M4 8v8l8 4 8-4V8"/><path d="M12 12v8"/></symbol>
		<symbol id="dlc-oc-i-alert" viewBox="0 0 24 24"><path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.2v.1"/></symbol>
		<symbol id="dlc-oc-i-x" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></symbol>
		<symbol id="dlc-oc-i-return" viewBox="0 0 24 24"><path d="M9 14l-4-4 4-4"/><path d="M5 10h9a5 5 0 010 10h-3"/></symbol>
		<symbol id="dlc-oc-i-share" viewBox="0 0 24 24"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 12v6.5A1.5 1.5 0 006.5 20h11a1.5 1.5 0 001.5-1.5V12"/></symbol>
		<symbol id="dlc-oc-i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h8"/></symbol>
		<symbol id="dlc-oc-i-download" viewBox="0 0 24 24"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 19h14"/></symbol>
		<symbol id="dlc-oc-i-print" viewBox="0 0 24 24"><path d="M7 9V4h10v5"/><rect x="4" y="9" width="16" height="7" rx="2"/><path d="M7 14h10v6H7z"/></symbol>
		<symbol id="dlc-oc-i-link" viewBox="0 0 24 24"><path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/></symbol>
		<symbol id="dlc-oc-i-support" viewBox="0 0 24 24"><path d="M4 14v-2a8 8 0 0116 0v2"/><rect x="3" y="13" width="4" height="6" rx="1.5"/><rect x="17" y="13" width="4" height="6" rx="1.5"/></symbol>
		<symbol id="dlc-oc-i-bag" viewBox="0 0 24 24"><path d="M6 8h12l-1 12H7z"/><path d="M9 8V6a3 3 0 016 0v2"/></symbol>
		<symbol id="dlc-oc-i-step-received" viewBox="0 0 24 24"><path d="M6.5 3.5h11v17l-2.75-1.8-2.75 1.8-2.75-1.8-2.75 1.8z"/><path d="M9.5 8.5h5M9.5 12h5M9.5 15.5h2.5"/></symbol>
		<symbol id="dlc-oc-i-step-paid" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="12.5" rx="2.5"/><path d="M3 10.5h18"/><path d="M7 15h3.5"/></symbol>
		<symbol id="dlc-oc-i-step-preparing" viewBox="0 0 24 24"><path d="M20.5 3.5L3.5 10.6l6.8 2.6 2.6 6.8z"/><path d="M10.3 13.2l10.2-9.7"/></symbol>
		<symbol id="dlc-oc-i-step-delivered" viewBox="0 0 24 24"><rect x="3.5" y="9" width="17" height="11.5" rx="2"/><path d="M12 9v11.5M3.5 13.5h17"/><path d="M12 9c-1.4-2.9-5-3.6-5-1.3C7 9 9.6 9 12 9zM12 9c1.4-2.9 5-3.6 5-1.3C17 9 14.4 9 12 9z"/></symbol>
		<symbol id="dlc-oc-i-chat" viewBox="0 0 24 24"><path d="M4.2 19.8l1.3-3.6A7.6 7.6 0 1112 19.6a7.5 7.5 0 01-3.6-.9z"/><path d="M8.6 12h.01M12 12h.01M15.4 12h.01"/></symbol>
	</svg>

	<?php if ( '' !== $dlc_oc_before ) : ?>
		<div class="dlc-oc-extra dlc-oc-extra--top"><?php echo $dlc_oc_before; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of woocommerce_before_thankyou, printed as WooCommerce does. ?></div>
	<?php endif; ?>

	<div class="dlc-oc-card dlc-oc-hero" data-tone="<?php echo esc_attr( $dlc_oc_state['tone'] ); ?>">
		<span class="dlc-oc-hero__bg" aria-hidden="true"></span>
		<span class="dlc-oc-hero__prev" aria-hidden="true"></span>
		<div class="dlc-oc-hero__top">
			<div class="dlc-oc-emblem" aria-hidden="true">
				<span class="dlc-oc-emblem__halo"></span>
				<svg class="dlc-oc-emblem__arc" viewBox="0 0 56 56" focusable="false"><circle cx="28" cy="28" r="26"/></svg>
				<span class="dlc-oc-emblem__disc"><?php echo $dlc_oc_icon( $dlc_oc_emblems[ $dlc_oc_phase ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span>
			</div>
		</div>
		<?php if ( '' !== $dlc_oc_state['greeting'] ) : ?>
			<p class="dlc-oc-hero__greet"><?php echo esc_html( $dlc_oc_state['greeting'] ); ?></p>
		<?php endif; ?>
		<h2 id="dlc-oc-title" class="dlc-oc-hero__title" data-oc="headline"><?php echo esc_html( $dlc_oc_state['headline'] ); ?></h2>
		<p class="dlc-oc-hero__msg" data-oc="message"><?php echo esc_html( $dlc_oc_state['message'] ); ?></p>
		<p class="dlc-oc-hero__meta">
			<?php
			/* translators: %s: order number */
			echo esc_html( sprintf( __( 'Commande #%s', 'delicat-builder-v9' ), $dlc_oc_view['number'] ) . "\u{00A0}· " . $dlc_oc_view['date'] );
			?>
		</p>
		<div class="dlc-oc-hero__status">
			<span class="dlc-oc-pill" data-tone="<?php echo esc_attr( $dlc_oc_state['pill']['tone'] ); ?>" data-oc="pill"><?php echo esc_html( $dlc_oc_state['pill']['label'] ); ?></span>
			<p class="dlc-oc-live" data-state="<?php echo esc_attr( $dlc_oc_state['terminal'] ? 'final' : 'static' ); ?>">
				<span class="dlc-oc-live__dot" aria-hidden="true"></span>
				<span class="dlc-oc-live__label">
					<?php
					echo esc_html(
						$dlc_oc_state['terminal']
							/* translators: %s: time */
							? sprintf( __( 'Statut final · vérifié à %s', 'delicat-builder-v9' ), $dlc_oc_now )
							/* translators: %s: time */
							: sprintf( __( 'Vérifié à %s', 'delicat-builder-v9' ), $dlc_oc_now )
					);
					?>
				</span>
				<span class="dlc-oc-live__ago" aria-hidden="true"></span>
				<button type="button" class="dlc-oc-live__refresh" hidden><?php esc_html_e( 'Actualiser', 'delicat-builder-v9' ); ?></button>
			</p>
		</div>

		<?php
		/* 9.4.2: the rail stretch leading to the step in progress carries a moving glint. */
		$dlc_oc_flow = false;
		foreach ( $dlc_oc_state['steps'] as $dlc_oc_step ) {
			$dlc_oc_flow = $dlc_oc_flow || 'current' === $dlc_oc_step['state'];
		}
		?>
		<div class="dlc-oc-track" style="--p:<?php echo esc_attr( (string) $dlc_oc_state['progress'] ); ?>" data-at="<?php echo esc_attr( (string) (int) round( 3 * (float) $dlc_oc_state['progress'] ) ); ?>" data-flow="<?php echo $dlc_oc_flow ? '1' : '0'; ?>">
			<span class="dlc-oc-track__rail" aria-hidden="true"><span class="dlc-oc-track__fill"></span><span class="dlc-oc-track__flow"></span></span>
			<ol class="dlc-oc-steps" aria-label="<?php esc_attr_e( 'Suivi de la commande', 'delicat-builder-v9' ); ?>">
				<?php
				foreach ( $dlc_oc_state['steps'] as $dlc_oc_i => $dlc_oc_step ) :
					$dlc_oc_st   = $dlc_oc_step['state'];
					$dlc_oc_glyph = array(
						'done'  => 'check',
						'alert' => 'alert',
						'ended' => 'refunded' === $dlc_oc_phase ? 'return' : 'x',
					);
					?>
					<li class="dlc-oc-step is-<?php echo esc_attr( $dlc_oc_st ); ?>" data-key="<?php echo esc_attr( $dlc_oc_step['key'] ); ?>"<?php echo in_array( $dlc_oc_st, array( 'current', 'alert' ), true ) ? ' aria-current="step"' : ''; ?>>
						<span class="dlc-oc-step__node" aria-hidden="true"><svg class="dlc-oc-step__spin" viewBox="0 0 48 48" focusable="false"><circle cx="24" cy="24" r="22"/></svg><?php echo $dlc_oc_icon( 'step-' . $dlc_oc_step['key'], 'dlc-oc-i dlc-oc-step__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><span class="dlc-oc-step__badge"><?php echo $dlc_oc_icon( $dlc_oc_glyph[ $dlc_oc_st ] ?? 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span></span>
						<span class="dlc-oc-step__title"><span class="dlc-oc-step__long"><?php echo esc_html( $dlc_oc_step['title'] ); ?></span><span class="dlc-oc-step__short" aria-hidden="true"><?php echo esc_html( $dlc_oc_step['short'] ); ?></span></span>
						<span class="dlc-oc-sr dlc-oc-step__sr"><?php echo 'done' === $dlc_oc_st ? esc_html( ' — ' . __( 'terminé', 'delicat-builder-v9' ) ) : ( 'ended' === $dlc_oc_st ? esc_html( ' — ' . __( 'arrêté', 'delicat-builder-v9' ) ) : '' ); ?></span>
						<span class="dlc-oc-step__sub"><?php echo esc_html( $dlc_oc_step['subtitle'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>

		<div class="dlc-oc-cta" data-cta="<?php echo esc_attr( $dlc_oc_cta ); ?>" data-reserve="<?php echo $dlc_oc_view['cta_reserve'] ? '1' : '0'; ?>">
			<?php if ( '' !== $dlc_oc_view['pay_url'] && in_array( $dlc_oc_state['cta'], array( 'pay', 'retry' ), true ) ) : ?>
				<a class="dlc-oc-btn dlc-oc-btn--primary" data-cta-pay data-pay="<?php esc_attr_e( 'Payer maintenant', 'delicat-builder-v9' ); ?>" data-retry="<?php esc_attr_e( 'Réessayer le paiement', 'delicat-builder-v9' ); ?>" href="<?php echo esc_url( $dlc_oc_view['pay_url'] ); ?>"><?php echo 'retry' === $dlc_oc_state['cta'] ? esc_html__( 'Réessayer le paiement', 'delicat-builder-v9' ) : esc_html__( 'Payer maintenant', 'delicat-builder-v9' ); ?></a>
			<?php endif; ?>
			<a class="dlc-oc-btn<?php echo $dlc_oc_view['support_wa'] ? ' dlc-oc-btn--wa' : ''; ?>" data-cta-support href="<?php echo esc_url( $dlc_oc_view['support_url'] ); ?>"<?php echo $dlc_oc_view['support_wa'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo $dlc_oc_icon( $dlc_oc_view['support_wa'] ? 'chat' : 'support' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php echo $dlc_oc_view['support_wa'] ? esc_html__( 'Écrire au support sur WhatsApp', 'delicat-builder-v9' ) : esc_html__( 'Contacter le support', 'delicat-builder-v9' ); ?></a>
			<?php if ( $dlc_oc_receipt ) : ?>
				<button type="button" class="dlc-oc-btn dlc-oc-btn--soft" data-cta-share data-oc-share aria-haspopup="dialog" aria-controls="dlc-oc-sheet"><?php echo $dlc_oc_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Partager le reçu', 'delicat-builder-v9' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="dlc-oc-sr" role="status" aria-live="polite" aria-atomic="true" data-oc="announcer"></p>
	</div>

	<?php
	/* 9.4.2: a delivered order with no code, top-up note or codes hint to show would only repeat the
	 * hero (« Votre commande est livrée. »): the card is left out. Delivered is final, so no live
	 * change can need it later. */
	$dlc_oc_delivery_card = ! ( 'delivered' === $dlc_oc_phase && '' === $dlc_oc_codes
		&& ( $dlc_oc_state['delivery']['has_code_line'] || '' === $dlc_oc_view['player'] )
		&& ! ( $dlc_oc_view['codes_hint'] && '' !== $dlc_oc_view['account_url'] ) );
	if ( $dlc_oc_delivery_card ) :
	?>
	<section class="dlc-oc-card dlc-oc-delivery<?php echo esc_attr( $dlc_oc_stagger( 'end' !== $dlc_oc_state['group'] ) ); ?>" aria-labelledby="dlc-oc-delivery-title">
		<p class="dlc-oc-eyebrow"><?php esc_html_e( 'Livraison', 'delicat-builder-v9' ); ?></p>
		<h2 id="dlc-oc-delivery-title" class="dlc-oc-card__title"><?php echo esc_html( $dlc_oc_view['codes_title'] ); ?></h2>
		<p class="dlc-oc-when dlc-oc-delivery__msg" data-when="wait"><?php esc_html_e( 'Vos codes et recharges apparaîtront ici après la confirmation du paiement.', 'delicat-builder-v9' ); ?></p>
		<ul class="dlc-oc-when dlc-oc-rows" data-when="rows">
			<?php foreach ( $dlc_oc_view['lines'] as $dlc_oc_line ) : ?>
				<li class="dlc-oc-row" data-state="<?php echo esc_attr( $dlc_oc_line['state'] ); ?>">
					<span class="dlc-oc-row__name"><?php echo esc_html( $dlc_oc_line['name'] ); ?></span>
					<span class="dlc-oc-chip dlc-oc-row__chip" data-oc="chip"><?php echo esc_html( (string) ( $dlc_oc_config['i18n'][ $dlc_oc_line['state'] ] ?? '' ) ); ?></span>
					<span class="dlc-oc-row__bar" aria-hidden="true"><span></span></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( '' !== $dlc_oc_view['note'] ) : ?>
			<p class="dlc-oc-when dlc-oc-note" data-when="rows"><?php echo esc_html( $dlc_oc_view['note'] ); ?></p>
		<?php endif; ?>
		<div class="dlc-oc-when dlc-oc-delivery__msg" data-when="review">
			<p><?php esc_html_e( 'La livraison n’a pas pu être finalisée automatiquement. Elle s’affichera ici dès la fin de la vérification.', 'delicat-builder-v9' ); ?></p>
		</div>
		<div class="dlc-oc-when dlc-oc-delivery__msg" data-when="done">
			<?php if ( ! $dlc_oc_state['delivery']['has_code_line'] && '' !== $dlc_oc_view['player'] ) : ?>
				<p>
					<?php
					/* translators: %s: player ID */
					echo esc_html( sprintf( __( 'Recharge livrée sur l’ID %s. Aucun code à saisir.', 'delicat-builder-v9' ), $dlc_oc_view['player'] ) );
					?>
				</p>
			<?php elseif ( $dlc_oc_view['codes_hint'] && '' === $dlc_oc_codes && '' !== $dlc_oc_view['account_url'] ) : ?>
				<p><?php echo esc_html( Delicat_Builder_V9_Order_Status::fr( __( 'Retrouvez vos codes dans Mon compte › Commandes dès qu’ils sont disponibles.', 'delicat-builder-v9' ) ) ); ?> <a href="<?php echo esc_url( $dlc_oc_view['account_url'] ); ?>"><?php esc_html_e( 'Ouvrir mes commandes', 'delicat-builder-v9' ); ?></a></p>
			<?php elseif ( '' === $dlc_oc_codes ) : ?>
				<p><?php esc_html_e( 'Votre commande est livrée.', 'delicat-builder-v9' ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( '' !== $dlc_oc_codes ) : ?>
			<div class="dlc-oc-codes"><?php echo $dlc_oc_codes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup of the Digital Gateway and Shop2TopUp, escaped by them, printed as WooCommerce's details table does. ?></div>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<section class="dlc-oc-card dlc-oc-summary<?php echo esc_attr( $dlc_oc_stagger() ); ?>" aria-labelledby="dlc-oc-summary-title">
		<div class="dlc-oc-card__head">
			<div>
				<p class="dlc-oc-eyebrow"><?php esc_html_e( 'Récapitulatif', 'delicat-builder-v9' ); ?></p>
				<h2 id="dlc-oc-summary-title" class="dlc-oc-card__title"><?php esc_html_e( 'Votre commande', 'delicat-builder-v9' ); ?></h2>
			</div>
			<span class="dlc-oc-chip"><?php echo esc_html( $dlc_oc_view['count_label'] ); ?></span>
		</div>
		<dl class="dlc-oc-meta<?php echo $dlc_oc_view['total_wide'] ? ' dlc-oc-meta--wide' : ''; ?>">
			<div><dt><?php esc_html_e( 'Commande', 'delicat-builder-v9' ); ?></dt><dd>#<?php echo esc_html( $dlc_oc_view['number'] ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Date', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $dlc_oc_view['date'] ); ?></dd></div>
			<div class="dlc-oc-meta__pay"><dt><?php esc_html_e( 'Paiement', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $dlc_oc_view['payment'] ); ?></dd></div>
			<div class="dlc-oc-meta__total" data-paid="<?php echo $dlc_oc_state['paid'] ? '1' : '0'; ?>"><dt><?php echo esc_html( $dlc_oc_view['total_label'] ); ?></dt><dd><span data-oc-count class="dlc-oc-money"><?php echo esc_html( $dlc_oc_view['total'] ); ?></span></dd></div>
		</dl>
		<ul class="dlc-oc-lines">
			<?php foreach ( $dlc_oc_view['lines'] as $dlc_oc_line ) : ?>
				<li class="dlc-oc-line">
					<?php if ( '' !== $dlc_oc_line['thumb'] ) : ?>
						<img class="dlc-oc-line__thumb" src="<?php echo esc_url( $dlc_oc_line['thumb'] ); ?>" width="48" height="48" loading="lazy" decoding="async" alt="">
					<?php else : ?>
						<span class="dlc-oc-line__thumb dlc-oc-line__thumb--empty" aria-hidden="true"><?php echo $dlc_oc_icon( 'bag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span>
					<?php endif; ?>
					<div class="dlc-oc-line__main">
						<div class="dlc-oc-line__body">
							<p class="dlc-oc-line__name"><?php echo esc_html( $dlc_oc_line['name'] ); ?></p>
							<p class="dlc-oc-line__qty">
								<?php
								/* translators: %d: quantity */
								echo esc_html( sprintf( __( 'Qté %d', 'delicat-builder-v9' ), $dlc_oc_line['qty'] ) );
								?>
							</p>
							<?php if ( $dlc_oc_line['chips'] ) : ?>
								<p class="dlc-oc-line__chips">
									<?php foreach ( $dlc_oc_line['chips'] as $dlc_oc_chip ) : ?>
										<span class="dlc-oc-chip"><?php echo esc_html( $dlc_oc_chip ); ?></span>
									<?php endforeach; ?>
								</p>
							<?php endif; ?>
						</div>
						<span class="dlc-oc-money dlc-oc-line__total"><?php echo esc_html( $dlc_oc_line['total'] ); ?></span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<dl class="dlc-oc-totals">
			<?php foreach ( $dlc_oc_view['rows'] as $dlc_oc_row ) : ?>
				<div><dt><?php echo esc_html( $dlc_oc_row['label'] ); ?></dt><dd><span class="dlc-oc-money"><?php echo esc_html( $dlc_oc_row['value'] ); ?></span></dd></div>
			<?php endforeach; ?>
			<div class="dlc-oc-totals__grand"><dt><?php esc_html_e( 'Total', 'delicat-builder-v9' ); ?></dt><dd><span data-oc-count class="dlc-oc-money"><?php echo esc_html( $dlc_oc_view['total'] ); ?></span></dd></div>
		</dl>
	</section>

	<?php if ( '' !== $dlc_oc_method ) : ?>
		<section class="dlc-oc-card dlc-oc-instructions<?php echo esc_attr( $dlc_oc_stagger() ); ?>" aria-labelledby="dlc-oc-instructions-title">
			<p class="dlc-oc-eyebrow"><?php esc_html_e( 'Paiement', 'delicat-builder-v9' ); ?></p>
			<h2 id="dlc-oc-instructions-title" class="dlc-oc-card__title"><?php esc_html_e( 'Instructions de paiement', 'delicat-builder-v9' ); ?></h2>
			<div class="dlc-oc-instructions__body"><?php echo $dlc_oc_method; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of woocommerce_thankyou_{method}, printed as WooCommerce does. ?></div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $dlc_oc_view['customer'] ) ) : ?>
		<section class="dlc-oc-card dlc-oc-customer<?php echo esc_attr( $dlc_oc_stagger() ); ?>" aria-labelledby="dlc-oc-customer-title">
			<h2 id="dlc-oc-customer-title" class="dlc-oc-card__title"><?php esc_html_e( 'Vos coordonnées', 'delicat-builder-v9' ); ?></h2>
			<dl class="dlc-oc-totals dlc-oc-totals--plain">
				<?php if ( ! empty( $dlc_oc_view['customer']['name'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Nom', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $dlc_oc_view['customer']['name'] ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! empty( $dlc_oc_view['customer']['email'] ) ) : ?>
					<div><dt><?php esc_html_e( 'E-mail', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $dlc_oc_view['customer']['email'] ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! empty( $dlc_oc_view['customer']['phone'] ) ) : ?>
					<div><dt><?php esc_html_e( 'Téléphone', 'delicat-builder-v9' ); ?></dt><dd><?php echo esc_html( $dlc_oc_view['customer']['phone'] ); ?></dd></div>
				<?php endif; ?>
			</dl>
		</section>
	<?php endif; ?>

	<?php /* Left out (dlc-oc-support--off) when the hero slot already holds « Contacter le support »; decided here, at first render, never by a live change (no card appears or disappears while the page is open). */ ?>
	<section class="dlc-oc-card dlc-oc-support<?php echo esc_attr( 'support' === $dlc_oc_cta ? ' dlc-oc-support--off' : $dlc_oc_stagger() ); ?>" aria-labelledby="dlc-oc-support-title">
		<p class="dlc-oc-eyebrow"><?php esc_html_e( 'Assistance', 'delicat-builder-v9' ); ?></p>
		<h2 id="dlc-oc-support-title" class="dlc-oc-card__title"><?php echo esc_html( Delicat_Builder_V9_Order_Status::fr( __( 'Besoin d’aide ?', 'delicat-builder-v9' ) ) ); ?></h2>
		<p class="dlc-oc-support__text">
			<?php
			/* translators: %s: order number */
			echo esc_html( sprintf( __( 'Notre équipe peut consulter votre commande #%s.', 'delicat-builder-v9' ), $dlc_oc_view['number'] ) );
			if ( $dlc_oc_view['support_wa'] && '' !== $dlc_oc_view['support_num'] ) {
				echo ' ';
				/* translators: %s: WhatsApp number */
				echo wp_kses( sprintf( esc_html__( 'Réponse rapide sur WhatsApp au %s.', 'delicat-builder-v9' ), '<span class="dlc-oc-support__num">' . esc_html( $dlc_oc_view['support_num'] ) . '</span>' ), array( 'span' => array( 'class' => true ) ) );
			}
			?>
		</p>
		<?php if ( $dlc_oc_view['support_wa'] ) : ?>
			<a class="dlc-oc-btn dlc-oc-btn--wa" href="<?php echo esc_url( $dlc_oc_view['support_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo $dlc_oc_icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Écrire sur WhatsApp', 'delicat-builder-v9' ); ?></a>
		<?php else : ?>
			<a class="dlc-oc-btn" href="<?php echo esc_url( $dlc_oc_view['support_url'] ); ?>"><?php echo $dlc_oc_icon( 'support' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Contacter le support', 'delicat-builder-v9' ); ?></a>
		<?php endif; ?>
	</section>

	<div class="dlc-oc-actions<?php echo esc_attr( $dlc_oc_stagger() ); ?>">
		<?php if ( $dlc_oc_receipt ) : ?>
			<button type="button" class="dlc-oc-btn dlc-oc-btn--primary" data-oc-share aria-haspopup="dialog" aria-controls="dlc-oc-sheet"><?php echo $dlc_oc_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Partager le reçu', 'delicat-builder-v9' ); ?></button>
		<?php endif; ?>
		<?php if ( $dlc_oc_orders ) : ?>
			<a class="dlc-oc-btn<?php echo $dlc_oc_receipt ? '' : ' dlc-oc-btn--primary'; ?>" data-oc-next href="<?php echo esc_url( $dlc_oc_view['orders_url'] ); ?>"><?php esc_html_e( 'Mes commandes', 'delicat-builder-v9' ); ?></a>
		<?php endif; ?>
		<a class="dlc-oc-btn <?php echo $dlc_oc_receipt || $dlc_oc_orders ? 'dlc-oc-btn--ghost' : 'dlc-oc-btn--primary'; ?>"<?php echo $dlc_oc_orders ? '' : ' data-oc-next'; ?> href="<?php echo esc_url( $dlc_oc_view['shop_url'] ); ?>"><?php esc_html_e( 'Continuer mes achats', 'delicat-builder-v9' ); ?></a>
	</div>

	<?php if ( '' !== $dlc_oc_thankyou ) : ?>
		<div class="dlc-oc-extra"><?php echo $dlc_oc_thankyou; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of woocommerce_thankyou (tracking pixels, gateway notices), printed as WooCommerce does. ?></div>
	<?php endif; ?>

	<?php if ( $dlc_oc_receipt ) : ?>
		<?php /* The toast moves inside this dialog while it is open (a modal dialog makes the rest of the page inert). */ ?>
		<dialog class="dlc-oc-sheet" id="dlc-oc-sheet" aria-labelledby="dlc-oc-sheet-title">
			<div class="dlc-oc-sheet__panel">
				<div class="dlc-oc-sheet__head">
					<h2 id="dlc-oc-sheet-title" class="dlc-oc-card__title"><?php esc_html_e( 'Partager le reçu', 'delicat-builder-v9' ); ?></h2>
					<button type="button" class="dlc-oc-iconbtn" data-oc-close aria-label="<?php esc_attr_e( 'Fermer', 'delicat-builder-v9' ); ?>"><?php echo $dlc_oc_icon( 'x' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></button>
				</div>
				<div class="dlc-oc-sheet__preview">
					<canvas class="dlc-oc-sheet__canvas" width="364" height="560" role="img" aria-label="<?php /* translators: %s: order number */ echo esc_attr( sprintf( __( 'Aperçu du reçu n° %s', 'delicat-builder-v9' ), $dlc_oc_view['number'] ) ); ?>"></canvas>
				</div>
				<div class="dlc-oc-sheet__actions">
					<button type="button" class="dlc-oc-btn dlc-oc-btn--primary" data-oc-act="share"><?php echo $dlc_oc_icon( 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Partager', 'delicat-builder-v9' ); ?></button>
					<button type="button" class="dlc-oc-btn" data-oc-act="png"><?php echo $dlc_oc_icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Télécharger (PNG)', 'delicat-builder-v9' ); ?></button>
					<button type="button" class="dlc-oc-btn" data-oc-act="print" aria-describedby="dlc-oc-print-hint"><?php echo $dlc_oc_icon( 'print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Imprimer / PDF', 'delicat-builder-v9' ); ?></button>
					<?php if ( ! empty( $dlc_oc_receipt['link'] ) ) : ?>
						<button type="button" class="dlc-oc-btn" data-oc-act="link"><?php echo $dlc_oc_icon( 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Copier le lien', 'delicat-builder-v9' ); ?></button>
					<?php endif; ?>
				</div>
				<p class="dlc-oc-sheet__hint" id="dlc-oc-print-hint"><?php esc_html_e( 'Choisissez « Enregistrer en PDF » pour garder une copie.', 'delicat-builder-v9' ); ?></p>
				<p class="dlc-oc-sheet__hint"><?php esc_html_e( 'Les codes ne figurent jamais sur ce reçu.', 'delicat-builder-v9' ); ?></p>
			</div>
		</dialog>
		<script type="application/json" id="dlc-oc-receipt"><?php echo wp_json_encode( $dlc_oc_receipt, $dlc_oc_json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < > & ' " hex-escaped, inside a non-executed script element. ?></script>
	<?php endif; ?>
	<div class="dlc-oc-toast" role="status" aria-live="polite" aria-atomic="true"></div>
	<script type="application/json" id="dlc-oc-config"><?php echo wp_json_encode( $dlc_oc_config, $dlc_oc_json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < > & ' " hex-escaped, inside a non-executed script element. ?></script>
</section>
