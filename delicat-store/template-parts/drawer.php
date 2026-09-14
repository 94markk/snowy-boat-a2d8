<?php
/**
 * Mobile drawer: menu, categories, account and support links.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_woo = ds_is_woo();
$ds_wa  = ds_whatsapp_url( 'Bonjour, j\'ai besoin d\'aide.' );
?>
<div class="ds-drawer" id="ds-drawer" data-ds-drawer hidden>
	<div class="ds-drawer__backdrop" data-ds-drawer-close tabindex="-1"></div>
	<aside class="ds-drawer__panel" role="dialog" aria-modal="true" aria-label="Menu">
		<div class="ds-drawer__head">
			<strong class="ds-drawer__title"><?php bloginfo( 'name' ); ?></strong>
			<button type="button" class="ds-iconbtn" data-ds-drawer-close aria-label="Fermer le menu"><?php ds_the_icon( 'close' ); ?></button>
		</div>
		<p class="ds-drawer__tagline"><?php echo esc_html( (string) ds_opt( 'tagline' ) ); ?></p>

		<nav class="ds-drawer__nav" aria-label="Menu">
			<?php ds_nav( 'drawer', 'ds-drawer__menu' ); ?>
		</nav>

		<?php if ( $ds_woo ) : ?>
			<?php
			$ds_cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => true,
					'number'     => 12,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( is_array( $ds_cats ) && array() !== $ds_cats ) :
				?>
				<p class="ds-drawer__label">Catégories</p>
				<ul class="ds-drawer__cats">
					<?php foreach ( $ds_cats as $ds_cat ) : ?>
						<?php
						if ( in_array( $ds_cat->slug, array( 'uncategorized', 'non-classe' ), true ) ) {
							continue;
						}
						$ds_topic = ds_badge_topic_for( array( $ds_cat->slug, $ds_cat->name ) );
						$ds_icon  = $ds_topic ? ds_badge_topics()[ $ds_topic ]['icon'] : 'tag';
						?>
						<li><a href="<?php echo esc_url( get_term_link( $ds_cat ) ); ?>"><?php ds_the_icon( $ds_icon ); ?><span><?php echo esc_html( $ds_cat->name ); ?></span><small><?php echo (int) $ds_cat->count; ?></small></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="ds-drawer__label">Mon espace</p>
			<ul class="ds-drawer__links">
				<li><a href="<?php echo esc_url( ds_account_url() ); ?>"><?php ds_the_icon( 'user' ); ?><span><?php echo is_user_logged_in() ? 'Mon compte' : 'Se connecter / créer un compte'; ?></span></a></li>
				<?php if ( is_user_logged_in() ) : ?>
					<li><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>"><?php ds_the_icon( 'clock' ); ?><span>Mes commandes</span></a></li>
				<?php endif; ?>
				<?php if ( function_exists( 'ds_wallet_active' ) && ds_wallet_active() ) : ?>
					<li><a href="<?php echo esc_url( ds_wallet_url() ); ?>"><?php ds_the_icon( 'wallet' ); ?><span>Mon Wallet</span><?php if ( '' !== ds_wallet_balance_html() ) : ?><small><?php echo ds_wallet_balance_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></small><?php endif; ?></a></li>
				<?php endif; ?>
				<li><a href="<?php echo esc_url( ds_cart_url() ); ?>"><?php ds_the_icon( 'cart' ); ?><span>Panier</span></a></li>
			</ul>
		<?php endif; ?>

		<div class="ds-drawer__foot">
			<?php if ( '' !== $ds_wa ) : ?>
				<a class="ds-btn ds-btn--whatsapp" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener"><?php ds_the_icon( 'whatsapp' ); ?><span>Support WhatsApp</span></a>
			<?php endif; ?>
			<div class="ds-social">
				<?php foreach ( ds_social_links() as $ds_net => $ds_url ) : ?>
					<a href="<?php echo esc_url( $ds_url ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( ucfirst( $ds_net ) ); ?>"><?php ds_the_icon( $ds_net ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
	</aside>
</div>
