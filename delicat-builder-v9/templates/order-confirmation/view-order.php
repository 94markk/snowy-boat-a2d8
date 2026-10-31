<?php
/**
 * Order details « Commande #… » (Delicat Builder 9.5.1).
 * Replaces WooCommerce's myaccount/view-order.php while the order module is on.
 *
 * WooCommerce already checked current_user_can( 'view_order' ) before loading this file; the
 * owner is checked again here. Codes appear only in « Vos codes », printed by the Digital
 * Gateway and Shop2TopUp through WooCommerce's hooks. Sections that hold another plugin's
 * markup carry data-delicat-nav-full: the engine then opens and leaves this page as a full
 * page, so those plugins' own scripts start as they expect.
 *
 * Glyphs: 24 px Material-style filled icons (Apache 2.0 shapes), drawn inline.
 *
 * @package Delicat_Builder_V9
 * @var WC_Order $order    Set by wc_get_template().
 * @var int      $order_id Set by wc_get_template().
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Delicat_Builder_V9_Order_View', false ) || ! class_exists( 'Delicat_Builder_V9_Order_Status', false ) || ! class_exists( 'Delicat_Builder_V9_Order_Confirmation', false ) ) {
	/* Module not loaded on this request: WooCommerce's own template. */
	if ( function_exists( 'WC' ) && is_callable( array( WC(), 'plugin_path' ) ) && is_readable( WC()->plugin_path() . '/templates/myaccount/view-order.php' ) ) {
		include WC()->plugin_path() . '/templates/myaccount/view-order.php';
	}
	return;
}

$dlc_vo_order = isset( $order ) && is_object( $order ) && Delicat_Builder_V9_Order_View::can_view( $order ) ? $order : false;
if ( ! $dlc_vo_order ) {
	echo '<p class="dlc-vo-denied">' . esc_html__( 'Cette commande est introuvable.', 'delicat-builder-v9' ) . '</p>';
	return;
}

$dlc_vo_i = static function ( string $name, string $class = 'dlc-vo-i' ): string {
	return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="#dlc-vo-i-' . esc_attr( $name ) . '"></use></svg>';
};

Delicat_Builder_V9_Order_Status::reset();
$dlc_vo_state = Delicat_Builder_V9_Order_Status::state( $dlc_vo_order );
$dlc_vo_view  = Delicat_Builder_V9_Order_View::view( $dlc_vo_order, $dlc_vo_state );
$dlc_vo_phase = $dlc_vo_state['phase'];
$dlc_vo_tone  = $dlc_vo_state['tone'];
$dlc_vo_codes = ( $dlc_vo_state['paid'] && 'end' !== $dlc_vo_state['group'] ) ? Delicat_Builder_V9_Order_Confirmation::render_codes_zone( $dlc_vo_order ) : '';
$dlc_vo_extra = Delicat_Builder_V9_Order_View::extra_html( (int) $dlc_vo_order->get_id() );
$dlc_vo_json  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$dlc_vo_chip  = array(
	'waiting'   => __( 'En préparation', 'delicat-builder-v9' ),
	'moving'    => __( 'En cours', 'delicat-builder-v9' ),
	'delivered' => __( 'Livrée', 'delicat-builder-v9' ),
	'review'    => __( 'À vérifier', 'delicat-builder-v9' ),
	'refunded'  => __( 'Remboursée', 'delicat-builder-v9' ),
);
$dlc_vo_rows  = 'rows' === $dlc_vo_state['group'] || 'review' === $dlc_vo_state['group'];
?>
<section class="dlc-vo" id="dlc-vo" data-dlc-vo data-phase="<?php echo esc_attr( $dlc_vo_phase ); ?>" data-tone="<?php echo esc_attr( $dlc_vo_tone ); ?>" aria-labelledby="dlc-vo-title">
	<svg class="dlc-vo-sprite" xmlns="http://www.w3.org/2000/svg" hidden aria-hidden="true" focusable="false">
		<symbol id="dlc-vo-i-check" viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></symbol>
		<symbol id="dlc-vo-i-check-circle" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></symbol>
		<symbol id="dlc-vo-i-schedule" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></symbol>
		<symbol id="dlc-vo-i-verified" viewBox="0 0 24 24"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></symbol>
		<symbol id="dlc-vo-i-bolt" viewBox="0 0 24 24"><path d="M11 21h-1l1-7H7.5c-.88 0-.33-.75-.31-.78C8.48 10.94 10.42 7.54 13.01 3h1l-1 7h3.51c.4 0 .62.19.4.66C12.97 17.55 11 21 11 21z"/></symbol>
		<symbol id="dlc-vo-i-send" viewBox="0 0 24 24"><path d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/></symbol>
		<symbol id="dlc-vo-i-hourglass" viewBox="0 0 24 24"><path d="M6 2v6h.01L6 8.01 10 12l-4 4 .01.01H6V22h12v-5.99h-.01L18 16l-4-4 4-3.99-.01-.01H18V2H6zm10 14.5V20H8v-3.5l4-4 4 4z"/></symbol>
		<symbol id="dlc-vo-i-error" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></symbol>
		<symbol id="dlc-vo-i-cancel" viewBox="0 0 24 24"><path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></symbol>
		<symbol id="dlc-vo-i-close" viewBox="0 0 24 24"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></symbol>
		<symbol id="dlc-vo-i-undo" viewBox="0 0 24 24"><path d="M12.5 8c-2.65 0-5.05.99-6.9 2.6L2 7v9h9l-3.62-3.62c1.39-1.16 3.16-1.88 5.12-1.88 3.54 0 6.55 2.31 7.6 5.5l2.37-.78C21.08 11.03 17.15 8 12.5 8z"/></symbol>
		<symbol id="dlc-vo-i-priority" viewBox="0 0 24 24"><path d="M10.5 4h3v10h-3zM10.5 16.5h3v3h-3z"/></symbol>
		<symbol id="dlc-vo-i-info" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></symbol>
		<symbol id="dlc-vo-i-receipt" viewBox="0 0 24 24"><path d="M18 17H6v-2h12v2zm0-4H6v-2h12v2zm0-4H6V7h12v2zM3 22l1.5-1.5L6 22l1.5-1.5L9 22l1.5-1.5L12 22l1.5-1.5L15 22l1.5-1.5L18 22l1.5-1.5L21 22V2l-1.5 1.5L18 2l-1.5 1.5L15 2l-1.5 1.5L12 2l-1.5 1.5L9 2 7.5 3.5 6 2 4.5 3.5 3 2v20z"/></symbol>
		<symbol id="dlc-vo-i-card" viewBox="0 0 24 24"><path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></symbol>
		<symbol id="dlc-vo-i-wallet" viewBox="0 0 24 24"><path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></symbol>
		<symbol id="dlc-vo-i-phone-pay" viewBox="0 0 24 24"><path d="M17 1.01 7 1c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-1.99-2-1.99zM17 19H7V5h10v14zm-4.2-5.76v1.75L16 12l-3.2-2.98v1.7c-3.11.43-4.35 2.56-4.8 4.7 1.11-1.5 2.58-2.18 4.8-2.18z"/></symbol>
		<symbol id="dlc-vo-i-flag" viewBox="0 0 24 24"><path d="M14.4 6 14 4H5v17h2v-7h5.6l.4 2h7V6z"/></symbol>
		<symbol id="dlc-vo-i-gift" viewBox="0 0 24 24"><path d="M20 6h-2.18c.11-.31.18-.65.18-1 0-1.66-1.34-3-3-3-1.05 0-1.96.54-2.5 1.35l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm11 15H4v-2h16v2zm0-5H4V8h5.08L7 10.83 8.62 12 11 8.76l1-1.36 1 1.36L15.38 12 17 10.83 14.92 8H20v6z"/></symbol>
		<symbol id="dlc-vo-i-person" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></symbol>
		<symbol id="dlc-vo-i-phone" viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></symbol>
		<symbol id="dlc-vo-i-mail" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/></symbol>
		<symbol id="dlc-vo-i-game" viewBox="0 0 24 24"><path d="M21.58 16.09l-1.09-7.66C20.21 6.46 18.52 5 16.53 5H7.47C5.48 5 3.79 6.46 3.51 8.43l-1.09 7.66C2.2 17.63 3.39 19 4.94 19c.68 0 1.32-.27 1.8-.75L9 16h6l2.25 2.25c.48.48 1.13.75 1.8.75 1.56 0 2.75-1.37 2.53-2.91zM11 11H9v2H8v-2H6v-1h2V8h1v2h2v1zm4-1c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm2 3c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/></symbol>
		<symbol id="dlc-vo-i-key" viewBox="0 0 24 24"><path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/></symbol>
		<symbol id="dlc-vo-i-headset" viewBox="0 0 24 24"><path d="M12 1c-4.97 0-9 4.03-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2c0-3.87 3.13-7 7-7s7 3.13 7 7v2h-4v8h3c1.66 0 3-1.34 3-3v-7c0-4.97-4.03-9-9-9z"/></symbol>
		<symbol id="dlc-vo-i-chat" viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM6 9h12v2H6V9zm8 5H6v-2h8v2zm4-6H6V6h12v2z"/></symbol>
		<symbol id="dlc-vo-i-replay" viewBox="0 0 24 24"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></symbol>
		<symbol id="dlc-vo-i-copy" viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></symbol>
		<symbol id="dlc-vo-i-note" viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></symbol>
		<symbol id="dlc-vo-i-sync" viewBox="0 0 24 24"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></symbol>
	</svg>

	<div class="dlc-vo-card dlc-vo-hero" data-tone="<?php echo esc_attr( $dlc_vo_tone ); ?>">
		<div class="dlc-vo-hero__text">
			<h2 id="dlc-vo-title" class="dlc-vo-hero__title">
				<?php
				/* translators: %s: order number */
				echo esc_html( sprintf( __( 'Commande #%s', 'delicat-builder-v9' ), $dlc_vo_view['number'] ) );
				?>
			</h2>
			<?php if ( '' !== $dlc_vo_view['placed'] ) : ?>
				<p class="dlc-vo-hero__date">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Passée le %s', 'delicat-builder-v9' ), $dlc_vo_view['placed'] ) );
					?>
				</p>
			<?php endif; ?>
			<p class="dlc-vo-pill" data-tone="<?php echo esc_attr( $dlc_vo_state['pill']['tone'] ); ?>"><?php echo $dlc_vo_i( $dlc_vo_view['glyph'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><span><?php echo esc_html( $dlc_vo_state['pill']['label'] ); ?></span></p>
		</div>
		<svg class="dlc-vo-art" data-scene="<?php echo esc_attr( $dlc_vo_view['scene'] ); ?>" viewBox="0 0 160 120" aria-hidden="true" focusable="false">
			<circle class="dlc-vo-art__halo" cx="104" cy="60" r="50"/>
			<circle class="dlc-vo-art__dot" cx="38" cy="22" r="4"/>
			<circle class="dlc-vo-art__dot" cx="150" cy="20" r="3"/>
			<circle class="dlc-vo-art__dot" cx="148" cy="104" r="5"/>
			<?php if ( 'moving' === $dlc_vo_view['scene'] ) : ?>
				<g class="dlc-vo-art__speed"><rect x="14" y="46" width="30" height="5" rx="2.5"/><rect x="4" y="60" width="40" height="5" rx="2.5"/><rect x="20" y="74" width="24" height="5" rx="2.5"/></g>
			<?php endif; ?>
			<?php if ( 'card' === $dlc_vo_view['scene'] ) : ?>
				<rect class="dlc-vo-art__body" x="52" y="34" width="96" height="62" rx="11"/>
				<rect class="dlc-vo-art__band" x="52" y="48" width="96" height="12"/>
				<rect class="dlc-vo-art__soft" x="64" y="72" width="30" height="8" rx="4"/>
			<?php elseif ( 'coins' === $dlc_vo_view['scene'] ) : ?>
				<ellipse class="dlc-vo-art__body" cx="98" cy="84" rx="38" ry="12"/>
				<rect class="dlc-vo-art__body" x="60" y="56" width="76" height="28"/>
				<ellipse class="dlc-vo-art__band" cx="98" cy="56" rx="38" ry="12"/>
			<?php else : ?>
				<rect class="dlc-vo-art__body" x="58" y="40" width="84" height="62" rx="10"/>
				<rect class="dlc-vo-art__band" x="52" y="30" width="96" height="20" rx="7"/>
				<rect class="dlc-vo-art__soft" x="92" y="30" width="16" height="72"/>
			<?php endif; ?>
			<circle class="dlc-vo-art__badge" cx="136" cy="92" r="20"/>
			<svg x="122" y="78" width="28" height="28" viewBox="0 0 24 24" class="dlc-vo-art__glyph"><use href="#dlc-vo-i-<?php echo esc_attr( $dlc_vo_view['glyph'] ); ?>"></use></svg>
		</svg>
	</div>

	<div class="dlc-vo-card dlc-vo-track">
		<ol class="dlc-vo-steps" aria-label="<?php esc_attr_e( 'Suivi de la commande', 'delicat-builder-v9' ); ?>">
			<?php foreach ( $dlc_vo_view['steps'] as $dlc_vo_step ) : ?>
				<li class="dlc-vo-step" data-state="<?php echo esc_attr( $dlc_vo_step['state'] ); ?>" data-in="<?php echo $dlc_vo_step['in'] ? '1' : '0'; ?>" data-out="<?php echo $dlc_vo_step['out'] ? '1' : '0'; ?>"<?php echo in_array( $dlc_vo_step['state'], array( 'current', 'alert' ), true ) ? ' aria-current="step"' : ''; ?>>
					<span class="dlc-vo-step__node"><?php echo $dlc_vo_i( $dlc_vo_step['glyph'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span>
					<span class="dlc-vo-step__title"><span class="dlc-vo-step__short" aria-hidden="true"><?php echo esc_html( $dlc_vo_step['short'] ); ?></span><span class="dlc-vo-sr"><?php echo esc_html( $dlc_vo_step['title'] ); ?></span></span>
					<span class="dlc-vo-step__sub"><?php echo esc_html( $dlc_vo_step['sub'] ); ?><?php if ( '' !== $dlc_vo_step['sub2'] ) : ?><span class="dlc-vo-step__time"><?php echo esc_html( $dlc_vo_step['sub2'] ); ?></span><?php endif; ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<p class="dlc-vo-track__msg"><?php echo $dlc_vo_i( 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><span><?php echo esc_html( $dlc_vo_state['message'] ); ?></span></p>
		<?php if ( $dlc_vo_view['live'] ) : ?>
			<p class="dlc-vo-live" data-vo="live" hidden><span class="dlc-vo-live__dot" aria-hidden="true"></span><span data-vo="live-label"><?php esc_html_e( 'Mise à jour automatique', 'delicat-builder-v9' ); ?></span></p>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $dlc_vo_codes ) : ?>
		<section class="dlc-vo-card dlc-vo-codes" data-delicat-nav-full aria-labelledby="dlc-vo-codes-title">
			<h3 id="dlc-vo-codes-title" class="dlc-vo-card__title"><?php echo $dlc_vo_i( 'key', 'dlc-vo-i dlc-vo-title-i' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php echo esc_html( $dlc_vo_state['delivery']['has_code_line'] ? __( 'Vos codes', 'delicat-builder-v9' ) : __( 'Votre livraison', 'delicat-builder-v9' ) ); ?></h3>
			<div class="dlc-vo-codes__body"><?php echo $dlc_vo_codes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup of the Digital Gateway and Shop2TopUp, escaped by them, printed as WooCommerce's details table does. ?></div>
		</section>
	<?php endif; ?>

	<section class="dlc-vo-card dlc-vo-items" aria-labelledby="dlc-vo-items-title">
		<div class="dlc-vo-card__head">
			<h3 id="dlc-vo-items-title" class="dlc-vo-card__title">
				<?php
				/* translators: %d: number of items */
				echo esc_html( sprintf( __( 'Articles commandés (%d)', 'delicat-builder-v9' ), $dlc_vo_view['count'] ) );
				?>
			</h3>
		</div>
		<ul class="dlc-vo-lines">
			<?php foreach ( $dlc_vo_view['lines'] as $dlc_vo_line ) : ?>
				<li class="dlc-vo-line">
					<?php if ( '' !== $dlc_vo_line['thumb'] ) : ?>
						<img class="dlc-vo-line__thumb" src="<?php echo esc_url( $dlc_vo_line['thumb'] ); ?>" width="56" height="56" loading="lazy" decoding="async" alt="">
					<?php else : ?>
						<span class="dlc-vo-line__thumb dlc-vo-line__thumb--empty" aria-hidden="true"><?php echo $dlc_vo_i( 'gift' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span>
					<?php endif; ?>
					<div class="dlc-vo-line__body">
						<div class="dlc-vo-line__top">
							<p class="dlc-vo-line__name"><?php echo esc_html( $dlc_vo_line['name'] ); ?></p>
							<span class="dlc-vo-money dlc-vo-line__total"><?php echo esc_html( $dlc_vo_line['total'] ); ?></span>
						</div>
						<p class="dlc-vo-line__meta">
							<?php
							/* translators: %d: quantity */
							echo esc_html( Delicat_Builder_V9_Order_Status::fr( sprintf( __( 'Quantité : %d', 'delicat-builder-v9' ), $dlc_vo_line['qty'] ) ) );
							?>
						</p>
						<?php if ( $dlc_vo_line['chips'] || $dlc_vo_rows ) : ?>
							<p class="dlc-vo-line__chips">
								<?php if ( $dlc_vo_rows && isset( $dlc_vo_chip[ $dlc_vo_line['state'] ] ) ) : ?>
									<span class="dlc-vo-chip dlc-vo-chip--state" data-state="<?php echo esc_attr( $dlc_vo_line['state'] ); ?>"><?php echo esc_html( $dlc_vo_chip[ $dlc_vo_line['state'] ] ); ?></span>
								<?php endif; ?>
								<?php foreach ( $dlc_vo_line['chips'] as $dlc_vo_c ) : ?>
									<span class="dlc-vo-chip"><?php echo esc_html( $dlc_vo_c ); ?></span>
								<?php endforeach; ?>
							</p>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>

	<div class="dlc-vo-duo">
		<?php if ( ! empty( $dlc_vo_view['contact'] ) || '' !== $dlc_vo_view['player'] ) : ?>
			<section class="dlc-vo-card dlc-vo-contact" aria-labelledby="dlc-vo-contact-title">
				<h3 id="dlc-vo-contact-title" class="dlc-vo-card__title"><?php esc_html_e( 'Vos coordonnées', 'delicat-builder-v9' ); ?></h3>
				<ul class="dlc-vo-facts">
					<?php if ( ! empty( $dlc_vo_view['contact']['name'] ) ) : ?>
						<li><span class="dlc-vo-facts__i"><?php echo $dlc_vo_i( 'person' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span><span class="dlc-vo-sr"><?php esc_html_e( 'Nom', 'delicat-builder-v9' ); ?> </span><span class="dlc-vo-facts__v"><?php echo esc_html( $dlc_vo_view['contact']['name'] ); ?></span></li>
					<?php endif; ?>
					<?php if ( ! empty( $dlc_vo_view['contact']['phone'] ) ) : ?>
						<li><span class="dlc-vo-facts__i"><?php echo $dlc_vo_i( 'phone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span><span class="dlc-vo-sr"><?php esc_html_e( 'Téléphone', 'delicat-builder-v9' ); ?> </span><span class="dlc-vo-facts__v"><?php echo esc_html( $dlc_vo_view['contact']['phone'] ); ?></span></li>
					<?php endif; ?>
					<?php if ( ! empty( $dlc_vo_view['contact']['email'] ) ) : ?>
						<li><span class="dlc-vo-facts__i"><?php echo $dlc_vo_i( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span><span class="dlc-vo-sr"><?php esc_html_e( 'E-mail', 'delicat-builder-v9' ); ?> </span><span class="dlc-vo-facts__v"><?php echo esc_html( $dlc_vo_view['contact']['email'] ); ?></span></li>
					<?php endif; ?>
					<?php if ( '' !== $dlc_vo_view['player'] ) : ?>
						<li><span class="dlc-vo-facts__i"><?php echo $dlc_vo_i( 'game' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span><span class="dlc-vo-facts__v"><?php echo esc_html( Delicat_Builder_V9_Order_Status::fr( sprintf( /* translators: %s: player ID */ __( 'ID joueur : %s', 'delicat-builder-v9' ), $dlc_vo_view['player'] ) ) ); ?></span></li>
					<?php endif; ?>
				</ul>
			</section>
		<?php endif; ?>

		<section class="dlc-vo-card dlc-vo-pay" aria-labelledby="dlc-vo-pay-title">
			<h3 id="dlc-vo-pay-title" class="dlc-vo-card__title"><?php esc_html_e( 'Mode de paiement', 'delicat-builder-v9' ); ?></h3>
			<div class="dlc-vo-pay__row">
				<span class="dlc-vo-pay__logo" aria-hidden="true"><?php echo $dlc_vo_i( $dlc_vo_view['payment']['glyph'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span>
				<div class="dlc-vo-pay__body">
					<p class="dlc-vo-pay__label"><?php echo esc_html( $dlc_vo_view['payment']['label'] ); ?></p>
					<?php if ( '' !== $dlc_vo_view['payment']['when'] ) : ?>
						<p class="dlc-vo-pay__when"><?php echo esc_html( $dlc_vo_view['payment']['when'] ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $dlc_vo_view['payment']['ref'] ) : ?>
						<p class="dlc-vo-pay__when">
							<?php
							/* translators: %s: payment reference */
							echo esc_html( Delicat_Builder_V9_Order_Status::fr( sprintf( __( 'Réf. : %s', 'delicat-builder-v9' ), $dlc_vo_view['payment']['ref'] ) ) );
							?>
						</p>
					<?php endif; ?>
				</div>
				<span class="dlc-vo-chip dlc-vo-chip--pay" data-tone="<?php echo esc_attr( $dlc_vo_view['payment']['chip']['tone'] ); ?>"><?php echo $dlc_vo_i( $dlc_vo_view['payment']['chip']['glyph'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php echo esc_html( $dlc_vo_view['payment']['chip']['label'] ); ?></span>
			</div>
		</section>
	</div>

	<section class="dlc-vo-card dlc-vo-sum" aria-labelledby="dlc-vo-sum-title">
		<h3 id="dlc-vo-sum-title" class="dlc-vo-card__title"><?php esc_html_e( 'Résumé du paiement', 'delicat-builder-v9' ); ?></h3>
		<dl class="dlc-vo-sum__rows">
			<?php foreach ( $dlc_vo_view['rows'] as $dlc_vo_row ) : ?>
				<div<?php echo $dlc_vo_row['minus'] ? ' class="is-minus"' : ''; ?>><dt><?php echo esc_html( $dlc_vo_row['label'] ); ?></dt><dd class="dlc-vo-money"><?php echo esc_html( $dlc_vo_row['value'] ); ?></dd></div>
			<?php endforeach; ?>
		</dl>
		<p class="dlc-vo-sum__total"><span><?php echo esc_html( $dlc_vo_view['total_label'] ); ?></span><strong class="dlc-vo-money"><?php echo esc_html( $dlc_vo_view['total'] ); ?></strong></p>
	</section>

	<?php if ( '' !== $dlc_vo_view['note'] ) : ?>
		<section class="dlc-vo-card dlc-vo-note" aria-labelledby="dlc-vo-note-title">
			<span class="dlc-vo-note__i" aria-hidden="true"><?php echo $dlc_vo_i( 'note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?></span>
			<div>
				<h3 id="dlc-vo-note-title" class="dlc-vo-card__title"><?php esc_html_e( 'Votre note', 'delicat-builder-v9' ); ?></h3>
				<p class="dlc-vo-note__text"><?php echo esc_html( $dlc_vo_view['note'] ); ?></p>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $dlc_vo_view['updates'] ) ) : ?>
		<section class="dlc-vo-card dlc-vo-updates" aria-labelledby="dlc-vo-updates-title">
			<h3 id="dlc-vo-updates-title" class="dlc-vo-card__title"><?php echo $dlc_vo_i( 'chat', 'dlc-vo-i dlc-vo-title-i' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Messages de la boutique', 'delicat-builder-v9' ); ?></h3>
			<ol class="dlc-vo-updates__list">
				<?php foreach ( $dlc_vo_view['updates'] as $dlc_vo_note ) : ?>
					<li>
						<?php if ( '' !== $dlc_vo_note['date'] ) : ?>
							<p class="dlc-vo-updates__date"><?php echo esc_html( $dlc_vo_note['date'] ); ?></p>
						<?php endif; ?>
						<div class="dlc-vo-updates__text"><?php echo $dlc_vo_note['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses_post() in the view, as WooCommerce prints customer notes. ?></div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
	<?php endif; ?>

	<?php if ( '' !== $dlc_vo_extra ) : ?>
		<div class="dlc-vo-extra" data-delicat-nav-full><?php echo $dlc_vo_extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of woocommerce_view_order, printed as WooCommerce does. ?></div>
	<?php endif; ?>

	<div class="dlc-vo-actions">
		<?php if ( '' !== $dlc_vo_view['pay_url'] ) : ?>
			<a class="dlc-vo-btn dlc-vo-btn--primary" href="<?php echo esc_url( $dlc_vo_view['pay_url'] ); ?>" data-delicat-nav="hard"><?php echo $dlc_vo_i( 'card' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php echo 'retry' === $dlc_vo_state['cta'] ? esc_html__( 'Réessayer le paiement', 'delicat-builder-v9' ) : esc_html__( 'Payer maintenant', 'delicat-builder-v9' ); ?></a>
		<?php endif; ?>
		<a class="dlc-vo-btn dlc-vo-btn--soft<?php echo '' === $dlc_vo_view['pay_url'] ? ' dlc-vo-btn--wide' : ''; ?>" href="<?php echo esc_url( $dlc_vo_view['support_url'] ); ?>"<?php echo $dlc_vo_view['support_wa'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo $dlc_vo_i( 'headset' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Contacter le support', 'delicat-builder-v9' ); ?></a>
		<?php if ( '' !== $dlc_vo_view['again_url'] ) : ?>
			<a class="dlc-vo-btn dlc-vo-btn--ghost dlc-vo-btn--wide" href="<?php echo esc_url( $dlc_vo_view['again_url'] ); ?>" data-delicat-nav="hard"><?php echo $dlc_vo_i( 'replay' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?><?php esc_html_e( 'Commander à nouveau', 'delicat-builder-v9' ); ?></a>
		<?php endif; ?>
	</div>
	<p class="dlc-vo-sr" role="status" aria-live="polite" data-vo="announcer"></p>
	<script type="application/json" class="dlc-vo-config"><?php echo wp_json_encode( $dlc_vo_view['config'], $dlc_vo_json ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < > & ' " hex-escaped, inside a non-executed script element. ?></script>
</section>
