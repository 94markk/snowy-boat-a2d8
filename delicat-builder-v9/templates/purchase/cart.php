<?php
/**
 * Purchase Studio native classic cart (RC51.58).
 *
 * WooCommerce owns every mutation and total. This presentation template keeps
 * the official cart form field names, nonce URLs, filters and extension hooks.
 *
 * @package Delicat_Builder_V9
 */
defined( 'ABSPATH' ) || exit;

$dpn = 'Delicat_Builder_V9_Purchase_Native';

do_action( 'woocommerce_before_cart' );

$dpn_count        = WC()->cart->get_cart_contents_count();
$dpn_summary_rows = array();
?>
<div class="dpn-shell">
	<header class="dpn-head dpn-head-cart">
		<a class="dpn-back" href="<?php echo esc_url( $dpn::shop_url() ); ?>" aria-label="<?php esc_attr_e( 'Continuer vos achats', 'delicat-builder-v9' ); ?>"><?php echo $dpn::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
		<div>
			<h1><?php esc_html_e( 'Panier', 'delicat-builder-v9' ); ?></h1>
			<p><?php echo esc_html( sprintf( _n( '%d article', '%d articles', $dpn_count, 'delicat-builder-v9' ), $dpn_count ) ); ?></p>
		</div>
		<button type="button" class="dpn-clear" data-dpn-clear form="dpn-cart-form" hidden><?php echo $dpn::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Tout effacer', 'delicat-builder-v9' ); ?></span></button>
	</header>

	<p class="dpn-hint">&larr; <?php esc_html_e( 'Glissez un article vers la gauche pour le supprimer', 'delicat-builder-v9' ); ?></p>

	<div class="dpn-cart-grid">
		<div class="dpn-cart-main">
			<?php do_action( 'woocommerce_before_cart_table' ); ?>

			<form id="dpn-cart-form" class="woocommerce-cart-form dpn-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
				<div class="dpn-items">
					<?php do_action( 'woocommerce_before_cart_contents' ); ?>
					<?php
					foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
				$_product  = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
				$product_id = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
				$visible    = apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key );

				if ( ! $_product instanceof WC_Product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! $visible ) {
					continue;
				}

				$product_name      = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );
				$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
				$remove_url        = wc_get_cart_remove_url( $cart_item_key );
				$badge             = $dpn::item_badge( $cart_item );
				$item_class        = apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key );
				$is_sold_one       = $_product->is_sold_individually();
				$min_qty           = $is_sold_one ? 1 : 0;
				$max_qty           = $is_sold_one ? 1 : $_product->get_max_purchase_quantity();
				$line_subtotal     = apply_filters(
					'woocommerce_cart_item_subtotal',
					WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ),
					$cart_item,
					$cart_item_key
				);
				$dpn_summary_rows[] = array(
					'name'     => wp_strip_all_tags( $product_name ),
					'quantity' => $cart_item['quantity'],
					'subtotal' => $line_subtotal,
				);

				$remove_link = sprintf(
					'<a role="button" href="%1$s" class="dpn-swipe-delete-link remove" aria-label="%2$s" data-product_id="%3$s" data-product_sku="%4$s" tabindex="-1" aria-hidden="true">%5$s<span>%6$s</span></a>',
					esc_url( $remove_url ),
					esc_attr( sprintf( __( 'Supprimer %s du panier', 'delicat-builder-v9' ), wp_strip_all_tags( $product_name ) ) ),
					esc_attr( $product_id ),
					esc_attr( $_product->get_sku() ),
					$dpn::icon( 'trash' ),
					esc_html__( 'Supprimer', 'delicat-builder-v9' )
				);
				$remove_link = apply_filters( 'woocommerce_cart_item_remove_link', $remove_link, $cart_item_key );

				$product_quantity = woocommerce_quantity_input(
					array(
						'input_name'  => "cart[{$cart_item_key}][qty]",
						'input_value' => $cart_item['quantity'],
						'max_value'   => $max_qty,
						'min_value'   => $min_qty,
						'product_name' => $product_name,
					),
					$_product,
					false
				);
				$product_quantity = apply_filters( 'woocommerce_cart_item_quantity', $product_quantity, $cart_item_key, $cart_item );
				?>
				<div class="dpn-swipe <?php echo esc_attr( $item_class ); ?>" data-dpn-swipe>
					<div class="dpn-swipe-delete">
						<?php echo $remove_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>

					<div class="dpn-card">
						<div class="dpn-card-top">
							<span class="dpn-thumb">
								<?php
								$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'woocommerce_thumbnail' ), $cart_item, $cart_item_key );
								if ( $product_permalink ) {
									echo '<a href="' . esc_url( $product_permalink ) . '">' . $thumbnail . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								} else {
									echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</span>

							<div class="dpn-copy">
								<?php if ( '' !== $badge ) : ?>
									<small class="dpn-badge"><?php echo esc_html( $badge ); ?></small>
								<?php endif; ?>
								<span class="dpn-name">
									<?php
									if ( $product_permalink ) {
										echo wp_kses_post( sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $product_name ) );
									} else {
										echo wp_kses_post( $product_name );
									}
									?>
								</span>
								<?php do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key ); ?>
								<?php
								$item_data = wc_get_formatted_cart_item_data( $cart_item );
								if ( '' !== $item_data ) {
									echo '<div class="dpn-meta">' . $item_data . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								if ( $_product->backorders_require_notification() && $_product->is_on_backorder( $cart_item['quantity'] ) ) {
									echo wp_kses_post(
										apply_filters(
											'woocommerce_cart_item_backorder_notification',
											'<p class="backorder_notification">' . esc_html__( 'Disponible sur commande', 'delicat-builder-v9' ) . '</p>',
											$product_id
										)
									);
								}
								?>
							</div>

							<span class="dpn-line-price">
								<?php echo $line_subtotal; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</span>
						</div>

						<div class="dpn-card-qty">
							<span class="dpn-qty-label"><?php esc_html_e( 'Quantité', 'delicat-builder-v9' ); ?></span>
							<span class="dpn-qty" data-dpn-qty data-remove="<?php echo esc_url( $remove_url ); ?>" data-sold-one="<?php echo $is_sold_one ? '1' : '0'; ?>">
								<button type="button" class="dpn-qty-minus" data-dpn-minus aria-label="<?php esc_attr_e( 'Réduire la quantité', 'delicat-builder-v9' ); ?>">
									<span class="dpn-qty-trash"><?php echo $dpn::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
									<span class="dpn-qty-dash" aria-hidden="true">&minus;</span>
								</button>
								<span class="dpn-native-quantity">
									<?php echo $product_quantity; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ( $is_sold_one ) : ?><span class="dpn-sold-count" aria-hidden="true">1</span><?php endif; ?>
								</span>
								<button type="button" class="dpn-qty-plus" data-dpn-plus aria-label="<?php esc_attr_e( 'Augmenter la quantité', 'delicat-builder-v9' ); ?>" <?php disabled( $is_sold_one ); ?>>+</button>
							</span>
							<noscript><a class="dpn-noscript-remove" href="<?php echo esc_url( $remove_url ); ?>"><?php esc_html_e( 'Supprimer', 'delicat-builder-v9' ); ?></a></noscript>
						</div>
					</div>
				</div>
				<?php
					}
					?>
					<?php do_action( 'woocommerce_cart_contents' ); ?>
				</div>

		<a class="dpn-continue" href="<?php echo esc_url( $dpn::shop_url() ); ?>"><?php echo $dpn::icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Continuer vos achats', 'delicat-builder-v9' ); ?></span></a>

		<?php if ( wc_coupons_enabled() ) : ?>
			<div class="dpn-coupon">
				<label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Code promo', 'delicat-builder-v9' ); ?></label>
				<input type="text" name="coupon_code" class="dpn-coupon-input input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Code promo', 'delicat-builder-v9' ); ?>">
				<button type="submit" class="dpn-coupon-apply button" name="apply_coupon" value="<?php esc_attr_e( 'Appliquer', 'delicat-builder-v9' ); ?>"><?php esc_html_e( 'Appliquer', 'delicat-builder-v9' ); ?></button>
				<?php do_action( 'woocommerce_cart_coupon' ); ?>
			</div>
		<?php endif; ?>

		<button type="submit" class="dpn-update button" name="update_cart" value="<?php esc_attr_e( 'Mettre à jour le panier', 'delicat-builder-v9' ); ?>" data-dpn-update><?php esc_html_e( 'Mettre à jour le panier', 'delicat-builder-v9' ); ?></button>
		<?php do_action( 'woocommerce_cart_actions' ); ?>
		<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>
		<?php do_action( 'woocommerce_after_cart_contents' ); ?>
			</form>

			<?php do_action( 'woocommerce_after_cart_table' ); ?>
			<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>
		</div>

		<section class="dpn-summary" aria-label="<?php esc_attr_e( 'Résumé de la commande', 'delicat-builder-v9' ); ?>">
		<h2><?php esc_html_e( 'Résumé de la commande', 'delicat-builder-v9' ); ?></h2>
		<div class="dpn-summary-items">
			<?php foreach ( $dpn_summary_rows as $dpn_summary_row ) : ?>
				<div class="dpn-summary-item">
					<span><?php echo esc_html( $dpn_summary_row['name'] ); ?> &times; <?php echo esc_html( $dpn_summary_row['quantity'] ); ?></span>
					<span><?php echo $dpn_summary_row['subtotal']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( function_exists( 'woocommerce_cart_totals' ) ) : ?>
			<div class="dpn-native-totals"><?php woocommerce_cart_totals(); ?></div>
		<?php endif; ?>

		<p class="dpn-trust">
			<span><?php echo $dpn::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Paiement sécurisé', 'delicat-builder-v9' ); ?></span>
			<span><?php echo $dpn::icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Livraison rapide', 'delicat-builder-v9' ); ?></span>
		</p>
		</section>
	</div>

	<?php
	$dpn_totals_priority = has_action( 'woocommerce_cart_collaterals', 'woocommerce_cart_totals' );
	if ( false !== $dpn_totals_priority ) {
		remove_action( 'woocommerce_cart_collaterals', 'woocommerce_cart_totals', $dpn_totals_priority );
	}
	ob_start();
	do_action( 'woocommerce_cart_collaterals' );
	$dpn_collaterals = trim( (string) ob_get_clean() );
	if ( false !== $dpn_totals_priority ) {
		add_action( 'woocommerce_cart_collaterals', 'woocommerce_cart_totals', $dpn_totals_priority );
	}
	if ( '' !== $dpn_collaterals ) {
		echo '<div class="cart-collaterals dpn-extra-collaterals">' . $dpn_collaterals . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	?>
</div>
<?php
do_action( 'woocommerce_after_cart' );
