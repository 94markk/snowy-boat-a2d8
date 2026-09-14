<?php
/**
 * Footer, tab bar and floating WhatsApp button.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_wa    = ds_whatsapp_url( 'Bonjour ' . get_bloginfo( 'name' ) . ', j\'ai une question.' );
$ds_legal = ds_legal_footer_links();
$ds_woo   = ds_is_woo();
?>
<footer class="ds-footer">
	<div class="ds-container">
		<div class="ds-footer__grid">
			<div class="ds-footer__brand">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<a class="ds-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="ds-logo__mark"><?php ds_the_icon( 'bolt' ); ?></span><span class="ds-logo__text"><?php bloginfo( 'name' ); ?></span></a>
				<?php endif; ?>
				<p class="ds-footer__tagline"><?php echo esc_html( (string) ds_opt( 'tagline' ) ); ?></p>
				<ul class="ds-trust ds-trust--footer">
					<li><?php ds_the_icon( 'bolt' ); ?><span>Livraison instantanée</span></li>
					<li><?php ds_the_icon( 'shield' ); ?><span>Paiement sécurisé</span></li>
					<li><?php ds_the_icon( 'support' ); ?><span>Support 7j/7</span></li>
				</ul>
				<div class="ds-social">
					<?php foreach ( ds_social_links() as $ds_net => $ds_url ) : ?>
						<a href="<?php echo esc_url( $ds_url ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( ucfirst( $ds_net ) ); ?>"><?php ds_the_icon( $ds_net ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="ds-footer__col">
				<h2 class="ds-footer__title">Boutique</h2>
				<?php
				$ds_cats = $ds_woo ? get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => true,
						'number'     => 8,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				) : array();
				?>
				<ul class="ds-footer__links">
					<?php if ( $ds_woo ) : ?><li><a href="<?php echo esc_url( ds_shop_url() ); ?>">Tous les produits</a></li><?php endif; ?>
					<?php if ( is_array( $ds_cats ) ) : ?>
						<?php foreach ( $ds_cats as $ds_cat ) : ?>
							<?php if ( in_array( $ds_cat->slug, array( 'uncategorized', 'non-classe' ), true ) ) { continue; } ?>
							<li><a href="<?php echo esc_url( get_term_link( $ds_cat ) ); ?>"><?php echo esc_html( $ds_cat->name ); ?></a></li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>

			<div class="ds-footer__col">
				<h2 class="ds-footer__title">Aide</h2>
				<?php ds_nav( 'footer', 'ds-footer__links' ); ?>
				<?php if ( '' !== $ds_wa ) : ?>
					<a class="ds-btn ds-btn--whatsapp ds-btn--small" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener"><?php ds_the_icon( 'whatsapp' ); ?><span>WhatsApp</span></a>
				<?php endif; ?>
			</div>

			<?php if ( array() !== $ds_legal ) : ?>
				<div class="ds-footer__col">
					<h2 class="ds-footer__title">Informations légales</h2>
					<ul class="ds-footer__links ds-footer__links--legal">
						<?php foreach ( $ds_legal as $ds_title => $ds_url ) : ?>
							<li><a href="<?php echo esc_url( $ds_url ); ?>"><?php echo esc_html( $ds_title ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>

		<div class="ds-footer__bottom">
			<p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. Tous droits réservés.</p>
			<p class="ds-footer__note">Produits numériques livrés par voie électronique. Vente réservée aux personnes majeures ou avec l'accord d'un parent.</p>
		</div>
	</div>
</footer>

<?php get_template_part( 'template-parts/tabbar' ); ?>

<?php if ( '' !== $ds_wa && ! ( $ds_woo && ( is_checkout() || is_cart() ) ) ) : ?>
	<a class="ds-fab" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener" aria-label="Support WhatsApp"><?php ds_the_icon( 'whatsapp' ); ?></a>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
