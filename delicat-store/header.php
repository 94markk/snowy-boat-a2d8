<?php
/**
 * Document head, app header and mobile drawer.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;
$ds_woo   = ds_is_woo();
$ds_count = ds_cart_count();
?>
<!doctype html>
<html <?php language_attributes(); ?> class="no-js">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="ds-skip" href="#main">Aller au contenu</a>

<header class="ds-header" id="top">
	<div class="ds-container ds-header__inner">
		<button type="button" class="ds-iconbtn ds-header__menu" data-ds-drawer-open aria-controls="ds-drawer" aria-expanded="false" aria-label="Ouvrir le menu"><?php ds_the_icon( 'menu' ); ?></button>

		<div class="ds-header__brand">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<a class="ds-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><span class="ds-logo__mark"><?php ds_the_icon( 'bolt' ); ?></span><span class="ds-logo__text"><?php bloginfo( 'name' ); ?></span></a>
			<?php endif; ?>
		</div>

		<nav class="ds-header__nav" aria-label="Navigation principale">
			<?php ds_nav( 'primary', 'ds-menu' ); ?>
		</nav>

		<form class="ds-header__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="ds-header-q">Rechercher un produit</label>
			<input id="ds-header-q" class="ds-search__input" type="search" name="s" list="<?php echo esc_attr( ds_search_datalist_id() ); ?>" placeholder="Rechercher un jeu, une carte, un abonnement…" autocomplete="off" value="<?php echo esc_attr( get_search_query() ); ?>">
			<?php if ( $ds_woo ) : ?><input type="hidden" name="post_type" value="product"><?php endif; ?>
			<button type="submit" class="ds-search__btn" aria-label="Rechercher"><?php ds_the_icon( 'search' ); ?></button>
		</form>

		<div class="ds-header__actions">
			<?php if ( function_exists( 'ds_wallet_chip' ) ) { ds_wallet_chip(); } ?>
			<button type="button" class="ds-iconbtn ds-theme-toggle" data-ds-theme-toggle aria-label="Basculer le mode sombre" title="Mode sombre / clair"><?php ds_the_icon( 'moon', 'ds-theme-toggle__moon' ); ?><?php ds_the_icon( 'sun', 'ds-theme-toggle__sun' ); ?></button>
			<a class="ds-iconbtn ds-header__search-link" href="<?php echo esc_url( ds_search_url() ); ?>" aria-label="Rechercher"><?php ds_the_icon( 'search' ); ?></a>
			<?php if ( $ds_woo ) : ?>
				<a class="ds-iconbtn ds-header__cart" href="<?php echo esc_url( ds_cart_url() ); ?>" aria-label="Panier"><?php ds_the_icon( 'cart' ); ?><span class="ds-cart-count<?php echo $ds_count > 0 ? ' is-visible' : ''; ?>"><?php echo (int) $ds_count; ?></span></a>
				<a class="ds-iconbtn ds-header__account" href="<?php echo esc_url( ds_account_url() ); ?>" aria-label="Mon compte"><?php ds_the_icon( 'user' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</header>

<?php get_template_part( 'template-parts/drawer' ); ?>
