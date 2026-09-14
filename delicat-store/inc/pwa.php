<?php
/**
 * Installable storefront: a web app manifest whose colours come from the
 * Customizer, so the splash screen matches the store. No service worker on
 * purpose: a cached document is a page that can be shown to the wrong person
 * or after its price changed. Speed on repeat visits comes from prefetching
 * (see ds_speculation_rules()).
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

function ds_manifest_url(): string {
	return add_query_arg( 'ds_manifest', '1', home_url( '/' ) );
}

/**
 * Icons: the site icon when one is set (Réglages › Général), else the
 * theme's own.
 *
 * @return array<int,array<string,string>>
 */
function ds_manifest_icons(): array {
	if ( has_site_icon() ) {
		return array(
			array( 'src' => get_site_icon_url( 192 ), 'sizes' => '192x192', 'type' => 'image/png' ),
			array( 'src' => get_site_icon_url( 512 ), 'sizes' => '512x512', 'type' => 'image/png' ),
		);
	}
	return array(
		array( 'src' => DS_URI . 'assets/img/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
		array( 'src' => DS_URI . 'assets/img/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
		array( 'src' => DS_URI . 'assets/img/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
	);
}

add_action(
	'wp_head',
	static function (): void {
		echo '<link rel="manifest" href="' . esc_url( ds_manifest_url() ) . '">' . "\n";
		if ( ! has_site_icon() ) {
			echo '<link rel="apple-touch-icon" href="' . esc_url( DS_URI . 'assets/img/icon-192.png' ) . '">' . "\n";
		}
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	},
	3
);

add_action(
	'template_redirect',
	static function (): void {
		if ( ! isset( $_GET['ds_manifest'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only.
			return;
		}
		$tokens   = ds_tokens();
		$manifest = array(
			'name'             => get_bloginfo( 'name' ),
			'short_name'       => mb_substr( get_bloginfo( 'name' ), 0, 12, 'UTF-8' ),
			'description'      => get_bloginfo( 'description' ),
			'start_url'        => home_url( '/?utm_source=pwa' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'lang'             => str_replace( '_', '-', get_locale() ),
			'background_color' => $tokens['ground'],
			'theme_color'      => $tokens['surface'],
			'icons'            => ds_manifest_icons(),
			'shortcuts'        => array(
				array( 'name' => 'Boutique', 'url' => ds_shop_url() ),
				array( 'name' => 'Panier', 'url' => ds_cart_url() ),
				array( 'name' => 'Mon compte', 'url' => ds_account_url() ),
			),
		);
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
);
