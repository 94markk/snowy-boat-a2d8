<?php
/**
 * Styles, scripts and the design-token layer.
 *
 * Order in <head>:
 *   1. theme-init (inline, priority 1): applies the saved light/dark choice to
 *      <html> before any stylesheet is parsed, so there is no flash.
 *   2. tokens (inline, priority 2): the Customizer's colours as custom
 *      properties. Every stylesheet reads var(--ds-*); no colour is written
 *      anywhere else.
 *   3. fonts, theme.css, WooCommerce's own styles.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cache-busting version: file modification time in development, the theme
 * version in production, so an updated theme ZIP always invalidates caches.
 */
function ds_asset_version( string $relative ): string {
	$path = DS_DIR . $relative;
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $path ) ) {
		return (string) filemtime( $path );
	}
	return DS_VERSION;
}

function ds_enqueue(): void {
	if ( (int) ds_opt( 'google_fonts' ) === 1 ) {
		wp_enqueue_style(
			'ds-fonts',
			'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google serves its own versioning.
		);
	}
	wp_enqueue_style( 'ds-theme', DS_URI . 'assets/css/theme.css', array(), ds_asset_version( 'assets/css/theme.css' ) );

	wp_enqueue_script( 'ds-theme', DS_URI . 'assets/js/theme.js', array(), ds_asset_version( 'assets/js/theme.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	wp_localize_script(
		'ds-theme',
		'dsConfig',
		array(
			'cartUrl'     => ds_cart_url(),
			'checkoutUrl' => ds_is_woo() && function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'i18n'        => array(
				'added'   => 'Ajouté au panier',
				'adding'  => 'Ajout…',
				'copied'  => 'Copié',
			),
		)
	);

	if ( ds_is_woo() && is_product() ) {
		wp_enqueue_script( 'ds-product', DS_URI . 'assets/js/product.js', array( 'jquery' ), ds_asset_version( 'assets/js/product.js' ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'ds_enqueue' );

/**
 * WooCommerce's stylesheets are loaded into a cascade layer named `wc`,
 * declared below every layer of the theme. Layered rules always lose to
 * unlayered ones, so left as normal <link>s WooCommerce's own CSS would
 * override the theme's styling of its markup (product grid, gallery widths,
 * buttons) whatever the selector. Imported into a layer, the theme wins and
 * WooCommerce's CSS still provides everything the theme does not restate
 * (star-rating glyphs, gallery, select2).
 *
 * @param array<string,array<string,mixed>> $styles WooCommerce style handles.
 * @return array<string,array<string,mixed>>
 */
function ds_layer_woocommerce_styles( array $styles ): array {
	$GLOBALS['ds_wc_styles'] = $styles;
	return array();
}
add_filter( 'woocommerce_enqueue_styles', 'ds_layer_woocommerce_styles' );

function ds_print_layered_wc_styles(): void {
	if ( empty( $GLOBALS['ds_wc_styles'] ) || ! is_array( $GLOBALS['ds_wc_styles'] ) ) {
		return;
	}
	$css = '';
	foreach ( $GLOBALS['ds_wc_styles'] as $handle => $style ) {
		/* The small-screen sheet is WooCommerce's responsive-table hack, written
		   with !important — which, inside a first-declared layer, would beat
		   every rule of the theme. The theme lays out phones itself. */
		if ( empty( $style['src'] ) || 'woocommerce-smallscreen' === $handle ) {
			continue;
		}
		$src   = add_query_arg( 'ver', rawurlencode( (string) ( $style['version'] ?? '' ) ), (string) $style['src'] );
		$media = isset( $style['media'] ) && 'all' !== $style['media'] ? ' ' . $style['media'] : '';
		$css  .= '@import url("' . esc_url( $src ) . '") layer(wc)' . $media . ';';
	}
	if ( '' !== $css ) {
		echo '<style id="ds-wc-layer">@layer wc;' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URLs escaped above.
	}
}
add_action( 'wp_head', 'ds_print_layered_wc_styles', 4 );

/**
 * Preconnect for the font host; harmless when fonts are disabled.
 *
 * @param array<int,array<string,string>> $urls  URLs.
 * @param string                          $relation_type Relation.
 * @return array<int,array<string,string>>
 */
function ds_resource_hints( array $urls, string $relation_type ): array {
	if ( 'preconnect' === $relation_type && (int) ds_opt( 'google_fonts' ) === 1 ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous' );
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'ds_resource_hints', 10, 2 );

/**
 * Apply the saved colour scheme before first paint.
 */
function ds_theme_init_script(): void {
	echo '<script>(function(){try{var t=localStorage.getItem("ds-theme");if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>' . "\n";
}
add_action( 'wp_head', 'ds_theme_init_script', 1 );

/**
 * Resolve the token set from the Customizer.
 *
 * @return array<string,string>
 */
function ds_tokens(): array {
	$brand   = ds_hex( ds_opt( 'color_brand' ), ds_defaults()['color_brand'] );
	$accent  = ds_hex( ds_opt( 'color_accent' ), ds_defaults()['color_accent'] );
	$ground  = ds_hex( ds_opt( 'color_ground' ), ds_defaults()['color_ground'] );
	$surface = ds_hex( ds_opt( 'color_surface' ), ds_defaults()['color_surface'] );
	$ink     = ds_hex( ds_opt( 'color_ink' ), ds_defaults()['color_ink'] );
	$radius  = max( 0, min( 32, (int) ds_opt( 'radius' ) ) );

	return array(
		'brand'        => $brand,
		'brand-ink'    => ds_ink_for( $brand ),
		'brand-soft'   => ds_shade( $brand, 0.88 ),
		'brand-strong' => ds_shade( $brand, -0.18 ),
		'accent'       => $accent,
		'accent-ink'   => ds_ink_for( $accent ),
		'accent-soft'  => ds_shade( $accent, 0.88 ),
		'ground'       => $ground,
		'surface'      => $surface,
		'sunken'       => ds_shade( $ground, -0.04 ),
		'line'         => ds_shade( $ground, -0.09 ),
		'ink'          => $ink,
		'ink-soft'     => ds_shade( $ink, 0.25 ),
		'ink-muted'    => ds_shade( $ink, 0.5 ),
		'radius'       => $radius . 'px',
		'radius-sm'    => max( 4, (int) round( $radius * 0.7 ) ) . 'px',
		'radius-lg'    => (int) round( $radius * 1.5 ) . 'px',
		/* Dark: brand and accent lifted for contrast on a dark ground. */
		'dark-brand'   => ds_shade( $brand, 0.18 ),
		'dark-brand-ink' => ds_ink_for( ds_shade( $brand, 0.18 ) ),
		'dark-accent'  => ds_shade( $accent, 0.2 ),
	);
}

/**
 * Print the :root token block. Priority 2: after charset, before stylesheets.
 */
function ds_print_tokens(): void {
	$tokens = ds_tokens();
	$css    = ':root{';
	foreach ( $tokens as $key => $value ) {
		$css .= '--ds-' . $key . ':' . $value . ';';
	}
	$css .= '}';
	echo '<style id="ds-tokens">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values validated in ds_tokens().
	echo '<meta name="theme-color" media="(prefers-color-scheme: light)" content="' . esc_attr( $tokens['surface'] ) . '">' . "\n";
	echo '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0d16">' . "\n";
}
add_action( 'wp_head', 'ds_print_tokens', 2 );

/**
 * Viewport with safe-area support for notched phones.
 */
function ds_viewport(): void {
	echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
}
add_action( 'wp_head', 'ds_viewport', 0 );

/**
 * Admin styles for the product-field editor and the setup screen.
 */
function ds_admin_enqueue( string $hook ): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$is_product = $screen && 'product' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true );
	$is_setup   = false !== strpos( $hook, 'delicat-store' );
	if ( ! $is_product && ! $is_setup ) {
		return;
	}
	wp_enqueue_style( 'ds-admin', DS_URI . 'assets/css/admin.css', array(), ds_asset_version( 'assets/css/admin.css' ) );
	if ( $is_product ) {
		wp_enqueue_script( 'ds-admin-fields', DS_URI . 'assets/js/admin-fields.js', array(), ds_asset_version( 'assets/js/admin-fields.js' ), true );
	}
}
add_action( 'admin_enqueue_scripts', 'ds_admin_enqueue' );

/**
 * Trim what WordPress prints that a storefront does not need.
 */
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );
add_filter( 'emoji_svg_url', '__return_false' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
