<?php
namespace Delicat\V10\App;

use Delicat\V10\Context;
use Delicat\V10\Design\Tokens;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The web app manifest, so the store can be installed to a home screen.
 *
 * Its colours come from the token layer, so an installed app's splash screen
 * and title bar match the store rather than drifting from it - which is what
 * happens whenever a manifest is a static file someone has to remember to edit.
 */
final class Manifest extends Module {

	public const PATH = 'delicat-v10.webmanifest';

	public static function kinds(): array {
		return array( Context::KIND_FRONT );
	}

	public static function priority(): int {
		return 41;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_var' ) );
		add_action( 'template_redirect', array( $this, 'serve' ), 0 );
		add_action( 'wp_head', array( $this, 'link' ), 3 );
	}

	public function add_rewrite(): void {
		add_rewrite_rule( '^' . self::PATH . '$', 'index.php?delicat_v10_manifest=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public function query_var( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = 'delicat_v10_manifest';
		return $vars;
	}

	public function link(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}
		printf( '<link rel="manifest" href="%s">' . "\n", esc_url( home_url( '/' . self::PATH ) ) );
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
	}

	public function serve(): void {
		if ( ! get_query_var( 'delicat_v10_manifest' ) ) {
			return;
		}

		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo wp_json_encode( self::document(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function document(): array {
		$name = trim( (string) get_bloginfo( 'name', 'display' ) );
		if ( '' === $name ) {
			$name = __( 'Boutique', 'delicat-v10' );
		}

		$manifest = array(
			'name'             => $name,
			'short_name'       => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 12 ) : substr( $name, 0, 12 ),
			'start_url'        => home_url( '/' ),
			'scope'            => (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ),
			/*
			 * `standalone`, not `fullscreen`: an installed store still needs the
			 * status bar - a customer checking the time or their signal mid-
			 * purchase should not have to leave the app to do it.
			 */
			'display'          => 'standalone',
			'orientation'      => 'portrait-primary',
			'background_color' => (string) Tokens::get( 'color', 'ground', '#f5f6fb' ),
			'theme_color'      => (string) Tokens::get( 'color', 'surface', '#ffffff' ),
			'lang'             => (string) get_bloginfo( 'language' ),
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'icons'            => self::icons(),
		);

		/** @param array<string,mixed> $manifest */
		return (array) apply_filters( 'delicat_v10_manifest', $manifest );
	}

	/**
	 * The site icon, at the sizes an install actually uses.
	 *
	 * `maskable` matters: without it Android draws the icon inside a white
	 * circle with a border, which is why so many installed web apps look
	 * unfinished next to native ones on that platform.
	 *
	 * @return array<int,array<string,string>>
	 */
	private static function icons(): array {
		$id = (int) get_option( 'site_icon' );
		if ( $id <= 0 ) {
			return array();
		}

		$icons = array();
		foreach ( array( 192, 512 ) as $size ) {
			$url = wp_get_attachment_image_url( $id, array( $size, $size ) );
			if ( ! $url ) {
				continue;
			}
			$icons[] = array(
				'src'     => (string) $url,
				'sizes'   => $size . 'x' . $size,
				'type'    => 'image/png',
				'purpose' => 'any maskable',
			);
		}

		return $icons;
	}
}
