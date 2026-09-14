<?php
namespace Delicat\V10;

defined( 'ABSPATH' ) || exit;

/**
 * Every stylesheet and script the storefront loads, and nothing else.
 *
 * -----------------------------------------------------------------------------
 * What this replaces
 * -----------------------------------------------------------------------------
 * V9 shipped 114 stylesheets and scripts, each checked into the repository
 * twice - once under its own name and once under a content-addressed twin - a
 * hand-written PHP map between them, a JSON integrity manifest, a combined
 * "chrome" bundle that no tool in the repository could build, and a second
 * combined bundle for components. The chrome bundle had not been rebuilt since
 * long before the edits it was supposed to contain, so four stylesheets' worth
 * of fixes were being discarded on every page load without a single error.
 *
 * V10 has one bundle, built by build/build.mjs, named after the hash of its own
 * contents, and recorded in a manifest the build writes. There is no second
 * copy of anything, no map to maintain and no file a human is expected to keep
 * in step by hand. If the bundle is stale the hash says so.
 */
final class Assets extends Module {

	public const HANDLE = 'delicat-v10';

	public static function priority(): int {
		return 2;
	}

	public static function kinds(): array {
		return array( Context::KIND_FRONT );
	}

	/** @var array<string,string>|null */
	private static $manifest = null;

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 5 );

		/* Scripts are deferred, never async: ui.js touches the DOM and async
		 * would let it run against a half-parsed document. Deferred keeps the
		 * document order guarantee while still not blocking the parser. */
		add_filter( 'script_loader_tag', array( $this, 'defer' ), 10, 3 );
	}

	public function enqueue(): void {
		if ( ! Context::instance()->is_page_view() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, self::url( 'app.css' ), array(), null );

		wp_enqueue_script( self::HANDLE . '-nav', self::url( 'nav.js' ), array(), null, true );
		wp_enqueue_script( self::HANDLE . '-ui', self::url( 'ui.js' ), array(), null, true );
	}

	/**
	 * The public URL of a built asset.
	 *
	 * Returns the hashed filename the manifest records, with no query string:
	 * the hash is the version. A missing manifest entry falls back to the plain
	 * name plus the plugin version, so a half-deployed build degrades to a
	 * cacheable-but-not-immutable asset rather than a 404.
	 */
	public static function url( string $name ): string {
		$manifest = self::manifest();

		if ( isset( $manifest[ $name ] ) ) {
			return DELICAT_V10_URL . 'dist/' . $manifest[ $name ];
		}

		return DELICAT_V10_URL . 'dist/' . $name . '?v=' . rawurlencode( DELICAT_V10_VERSION );
	}

	/** @return array<string,string> */
	public static function manifest(): array {
		if ( null !== self::$manifest ) {
			return self::$manifest;
		}

		self::$manifest = array();

		$path = DELICAT_V10_DIR . 'dist/manifest.json';
		if ( is_file( $path ) ) {
			$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, no HTTP.
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $from => $to ) {
					if ( is_string( $from ) && is_string( $to ) ) {
						self::$manifest[ $from ] = $to;
					}
				}
			}
		}

		return self::$manifest;
	}

	/**
	 * @param string $tag
	 * @param string $handle
	 * @param string $src
	 */
	public function defer( $tag, $handle, $src ): string {
		if ( 0 !== strpos( (string) $handle, self::HANDLE ) ) {
			return (string) $tag;
		}
		if ( false !== strpos( (string) $tag, ' defer' ) ) {
			return (string) $tag;
		}
		return str_replace( '<script ', '<script defer ', (string) $tag );
	}

	/**
	 * Inline CSS that must arrive with the document because the page cannot be
	 * drawn correctly without it. Deliberately tiny and deliberately not a
	 * "critical CSS" pipeline: the token layer plus the shell frame is enough
	 * to paint the chrome in the right place, and everything else can wait one
	 * round trip.
	 *
	 * V9 inlined 8KB of hand-maintained critical CSS that had drifted from the
	 * stylesheets it was extracted from.
	 */
	public static function inline_shell_css(): string {
		return '.dlx-app{display:flex;flex-direction:column;min-height:100svh}'
			. '.dlx-main{flex:1 1 auto;padding-bottom:var(--dlx-band)}'
			. '.dlx-header{position:sticky;top:0;z-index:var(--dlx-z-header);display:flex;align-items:center;'
			. 'height:calc(var(--dlx-header-h) + var(--dlx-safe-t));padding-top:var(--dlx-safe-t);'
			. 'padding-inline:var(--dlx-gutter);background:var(--dlx-surface);'
			. 'border-bottom:1px solid var(--dlx-line-soft)}'
			. '.dlx-tabbar{position:fixed;inset-inline:0;bottom:0;z-index:var(--dlx-z-tabbar);'
			. 'display:grid;grid-auto-flow:column;grid-auto-columns:1fr;'
			. 'height:calc(var(--dlx-tabbar-h) + var(--dlx-safe-b));padding-bottom:var(--dlx-safe-b);'
			. 'background:var(--dlx-surface);border-top:1px solid var(--dlx-line-soft)}';
	}
}
