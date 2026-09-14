<?php
/**
 * Bottom tab bar (phones and tablets).
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
?>
<nav class="ds-tabbar" aria-label="Navigation rapide">
	<?php foreach ( ds_tabs() as $ds_tab ) : ?>
		<a class="ds-tab<?php echo $ds_tab['active'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $ds_tab['url'] ); ?>" <?php echo $ds_tab['active'] ? 'aria-current="page"' : ''; ?>>
			<span class="ds-tab__icon"><?php ds_the_icon( $ds_tab['icon'] ); ?>
				<?php if ( 'cart' === $ds_tab['key'] ) : ?>
					<span class="ds-cart-count<?php echo ( $ds_tab['count'] ?? 0 ) > 0 ? ' is-visible' : ''; ?>"><?php echo (int) ( $ds_tab['count'] ?? 0 ); ?></span>
				<?php endif; ?>
			</span>
			<span class="ds-tab__label"><?php echo esc_html( $ds_tab['label'] ); ?></span>
		</a>
	<?php endforeach; ?>
</nav>
