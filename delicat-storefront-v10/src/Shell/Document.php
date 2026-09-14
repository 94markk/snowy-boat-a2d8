<?php
namespace Delicat\V10\Shell;

use Delicat\V10\Assets;
use Delicat\V10\Context;
use Delicat\V10\Design\Tokens;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The document itself: what goes in <head>, what classes the <body> carries,
 * and where the shell opens and closes around the theme's content.
 *
 * V10 does not replace the theme's templates. It wraps them. A theme that
 * renders WooCommerce correctly keeps rendering it correctly; V10 supplies the
 * chrome, the tokens and the transitions around it. That is why there is no
 * template hierarchy here and no page builder to fight with.
 */
final class Document extends Module {

	public static function priority(): int {
		return 5;
	}

	public function register(): void {
		add_action( 'wp_head', array( $this, 'head' ), 1 );
		add_filter( 'body_class', array( $this, 'body_class' ) );

		/* The shell opens as early as the theme allows and closes as late. Both
		 * hooks are core and every well-behaved theme fires them. */
		add_action( 'wp_body_open', array( $this, 'open' ), 1 );
		add_action( 'wp_footer', array( $this, 'close' ), 99 );

		add_filter( 'language_attributes', array( $this, 'html_attributes' ) );
	}

	/**
	 * Theme preference, applied before the first paint.
	 *
	 * This is the one blocking script in V10 and it is eleven lines, because
	 * the alternative is a flash of the wrong theme on every single page load.
	 * It runs in <head> before any body content is parsed, reads the stored
	 * choice, and writes the attribute the token layer keys on.
	 *
	 * The stored value is validated rather than trusted: it is written into an
	 * attribute, so "dark" and "light" are the only two strings that may reach
	 * it, whatever is in storage.
	 */
	public function head(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		echo "<script id=\"delicat-v10-theme\">(function(){try{var t=localStorage.getItem('dlx-theme');"
			. "if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-dlx-theme',t);}}"
			. "catch(e){}})();</script>\n";

		echo '<meta name="theme-color" content="' . esc_attr( (string) Tokens::get( 'color', 'surface', '#ffffff' ) ) . '" media="(prefers-color-scheme: light)">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( (string) Tokens::get( 'color-dark', 'surface', '#141726' ) ) . '" media="(prefers-color-scheme: dark)">' . "\n";

		/* viewport-fit=cover is what makes env(safe-area-inset-*) return a real
		 * number on a notched phone. Without it the tab bar sits under the home
		 * indicator. Themes rarely set it, so V10 states its own. */
		echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";

		echo '<style id="delicat-v10-shell">' . Assets::inline_shell_css() . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static string built in Assets.
	}

	/** @param string $output */
	public function html_attributes( $output ): string {
		/*
		 * `color-scheme` tells the browser to render its own furniture - form
		 * controls, scrollbars, the address bar - in the matching theme. Without
		 * it a dark storefront gets white scrollbars and white autofill panels.
		 */
		return (string) $output . ' data-dlx-app';
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes   = is_array( $classes ) ? $classes : array();
		$context   = Context::instance();

		if ( ! $context->is_page_view() ) {
			return $classes;
		}

		$classes[] = 'dlx';
		$classes[] = 'dlx-route-' . sanitize_html_class( $context->route() );

		/*
		 * These two classes are the entire mechanism by which the page knows
		 * how much of its bottom edge is covered. PHP states what it rendered;
		 * the token layer turns that into a measurement. Nothing else in V10
		 * has an opinion about it.
		 */
		if ( TabBar::will_render() ) {
			$classes[] = 'dlx-has-tabbar';
		}
		if ( Dock::will_render() ) {
			$classes[] = 'dlx-has-dock';
		}

		return $classes;
	}

	public function open(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}
		echo '<div class="dlx-app">';
		do_action( 'delicat_v10_shell_top' );
		echo '<main class="dlx-main" id="dlx-main">';
	}

	public function close(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}
		echo '</main>';
		do_action( 'delicat_v10_shell_bottom' );
		echo '</div>';
	}
}
