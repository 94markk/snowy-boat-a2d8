<?php
namespace Delicat\V10\Shell;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The slide-in menu.
 *
 * A real <dialog>, so focus trapping, Escape, inertness of the page behind and
 * the backdrop are the browser's job rather than three hundred lines of ours.
 * V9 built its drawer from a div, a scrim div, a body class and a focus-trap
 * loop, and lost focus containment whenever the router swapped the page under
 * an open drawer.
 */
final class Drawer extends Module {

	public static function priority(): int {
		return 25;
	}

	public function register(): void {
		add_action( 'delicat_v10_shell_bottom', array( $this, 'render' ), 10 );
	}

	public function render(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		echo '<dialog class="dlx-drawer" id="dlx-drawer" aria-label="' . esc_attr__( 'Menu', 'delicat-v10' ) . '">';

		self::identity();

		echo '<div class="dlx-drawer__body">';

		foreach ( Nav::items() as $item ) {
			printf(
				'<a class="dlx-drawer__item" href="%1$s"%2$s>%3$s<span>%4$s</span></a>',
				esc_url( (string) $item['url'] ),
				Nav::is_current( (string) $item['url'] ) ? ' aria-current="page"' : '',
				Icons::svg( (string) $item['icon'], 22 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
				esc_html( (string) $item['label'] )
			);
		}

		do_action( 'delicat_v10_drawer_items' );

		echo '</div>';
		echo '</dialog>';
	}

	/**
	 * The head of the drawer.
	 *
	 * Three rules, each one a V9 bug that the merchant photographed:
	 *
	 * 1. A line that has no text is not rendered. V9 fell back to the WordPress
	 *    site title, which is empty here, and printed an empty element at full
	 *    line height - the gap in the screenshot.
	 * 2. When there is no store name, the tagline becomes the main line rather
	 *    than sitting alone under a blank one.
	 * 3. If neither exists the whole identity block is omitted and the drawer
	 *    simply starts with its menu.
	 */
	private static function identity(): void {
		$name    = trim( (string) get_bloginfo( 'name', 'display' ) );
		$tagline = trim( (string) get_bloginfo( 'description', 'display' ) );

		$primary   = '' !== $name ? $name : $tagline;
		$secondary = '' !== $name ? $tagline : '';

		echo '<div class="dlx-drawer__head">';

		if ( '' !== $primary ) {
			printf(
				'<span class="dlx-drawer__avatar" aria-hidden="true">%s</span>',
				esc_html( self::initial( $primary ) )
			);

			echo '<span class="dlx-drawer__identity">';
			printf( '<strong>%s</strong>', esc_html( $primary ) );

			if ( '' !== $secondary ) {
				printf( '<small>%s</small>', esc_html( $secondary ) );
			}

			echo '</span>';
		}

		printf(
			'<button type="button" class="dlx-icon-btn" data-dlx-close style="margin-inline-start:auto" aria-label="%s">%s</button>',
			esc_attr__( 'Fermer', 'delicat-v10' ),
			Icons::svg( 'close', 22 ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed table.
		);

		echo '</div>';
	}

	/** First character of a name, upper-cased, multibyte-safe. */
	private static function initial( string $text ): string {
		$first = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1 ) : substr( $text, 0, 1 );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first ) : strtoupper( $first );
	}
}
