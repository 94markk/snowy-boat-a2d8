<?php
/**
 * Not found.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
get_header();
$ds_wa = ds_whatsapp_url( 'Bonjour, je cherche un produit qui n\'apparaît plus sur le site.' );
?>
<main id="main" class="ds-main">
	<div class="ds-container ds-container--narrow">
		<div class="ds-empty ds-empty--404">
			<p class="ds-empty__code">404</p>
			<h1 class="ds-page__title">Cette page n'existe pas (ou plus).</h1>
			<p>Le produit a peut-être changé de nom. Cherchez-le, ou demandez-nous directement.</p>
			<?php get_search_form(); ?>
			<p>
				<a class="ds-btn ds-btn--brand" href="<?php echo esc_url( ds_shop_url() ); ?>">Voir la boutique</a>
				<?php if ( '' !== $ds_wa ) : ?>
					<a class="ds-btn ds-btn--ghost" href="<?php echo esc_url( $ds_wa ); ?>" target="_blank" rel="noopener"><?php ds_the_icon( 'whatsapp' ); ?><span>Demander sur WhatsApp</span></a>
				<?php endif; ?>
			</p>
		</div>
	</div>
</main>
<?php
get_footer();
