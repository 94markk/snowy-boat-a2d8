<?php
namespace Delicat\V10\Shell;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The top bar: identity on the left, actions on the right, and on a wide window
 * the navigation in between.
 */
final class Header extends Module {

	public static function priority(): int {
		return 15;
	}

	public function register(): void {
		add_action( 'delicat_v10_shell_top', array( $this, 'render' ), 10 );
	}

	public function render(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		echo '<header class="dlx-header">';

		printf(
			'<button type="button" class="dlx-icon-btn" data-dlx-open="dlx-drawer" aria-label="%s">%s</button>',
			esc_attr__( 'Ouvrir le menu', 'delicat-v10' ),
			Icons::svg( 'menu', 22 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
		);

		self::brand();

		echo '<nav class="dlx-header__nav" aria-label="' . esc_attr__( 'Navigation', 'delicat-v10' ) . '">';
		foreach ( Nav::items() as $item ) {
			printf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( (string) $item['url'] ),
				Nav::is_current( (string) $item['url'] ) ? ' aria-current="page"' : '',
				esc_html( (string) $item['label'] )
			);
		}
		echo '</nav>';

		echo '<div class="dlx-header__actions">';

		printf(
			'<button type="button" class="dlx-icon-btn" data-dlx-theme-toggle aria-label="%s" aria-pressed="false"><span class="dlx-theme-light">%s</span><span class="dlx-theme-dark">%s</span></button>',
			esc_attr__( 'Changer de thème', 'delicat-v10' ),
			Icons::svg( 'moon', 22 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
			Icons::svg( 'sun', 22 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
		);

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			printf(
				'<a class="dlx-icon-btn" href="%s" aria-label="%s">%s</a>',
				esc_url( (string) wc_get_page_permalink( 'cart' ) ),
				esc_attr__( 'Panier', 'delicat-v10' ),
				Icons::svg( 'cart', 22 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
			);
		}

		echo '</div>';
		echo '</header>';
	}

	/**
	 * Identity.
	 *
	 * Prints the logo if there is one, the store name if there is one, and
	 * nothing at all if there is neither.
	 *
	 * V9 printed the element unconditionally with the WordPress site title as
	 * its text. On this install that title is empty, so the drawer and header
	 * rendered an empty strong element at full line height - the tall blank gap
	 * the merchant circled in their screenshot. An empty string is not a name,
	 * and the difference is checked here rather than assumed.
	 */
	private static function brand(): void {
		$name = trim( (string) get_bloginfo( 'name', 'display' ) );
		$logo = self::logo_html();

		if ( '' === $name && '' === $logo ) {
			return;
		}

		printf( '<a class="dlx-header__brand" href="%s">', esc_url( home_url( '/' ) ) );

		if ( '' !== $logo ) {
			echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output.
		}

		if ( '' !== $name ) {
			printf( '<span class="dlx-header__name">%s</span>', esc_html( $name ) );
		}

		echo '</a>';
	}

	private static function logo_html(): string {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return '';
		}

		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id <= 0 ) {
			return '';
		}

		/*
		 * fetchpriority high and no lazy loading: on most storefront pages the
		 * logo is inside the largest contentful paint's viewport and deferring
		 * it costs a visible beat. Explicit width and height so it reserves its
		 * own space before it arrives.
		 */
		$html = wp_get_attachment_image(
			$id,
			'medium',
			false,
			array(
				'class'         => 'dlx-header__logo',
				'loading'       => 'eager',
				'decoding'      => 'sync',
				'fetchpriority' => 'high',
				'alt'           => '',
			)
		);

		return is_string( $html ) ? $html : '';
	}
}
