<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC85 — scoped App Polish stability layer.
 *
 * Fixed UI emoji are converted or removed at their source. The legacy full-
 * document sweep remains available behind the
 * `delicat_builder_v9_app_polish_document_emoji_sweep` filter, but is disabled
 * by default so this module does not add another output-buffer dependency.
 *
 * Typography, tap targets and overflow rules are scoped to Builder-owned
 * storefront roots and named shell controls. App Tuning remains the single
 * owner of mobile type variables. Native browser pull-to-refresh is preserved;
 * the legacy custom gesture is opt-in only.
 *
 * Front end only. Safe Mode aware. PHP 8.5.
 */
final class Delicat_Builder_V9_App_Polish {

	const HANDLE = 'delicat-app-polish';

	/** @var array<string,string> */
	private static $protected = array();

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 998 );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 22 );

		/* RC82: sweep at the source, not only in the output buffer.
		 *
		 * The buffer sweep is one of 26 ob_start() calls in this plugin and it
		 * depends on WordPress, WooCommerce, the theme and every other plugin
		 * flushing in a compatible order. When any of them exit()s or discards
		 * a buffer the sweep silently never runs for that response, and the
		 * result reads as "the emoji bug is back".
		 *
		 * Titles, product names, category names and section copy are now
		 * converted where they are read. No buffer involved; the buffer stays
		 * as a second net for text these filters cannot reach. */
		if ( self::feature( 'source_sweep' ) ) {
			add_filter( 'the_title', array( __CLASS__, 'filter_text' ), 20, 1 );
			add_filter( 'woocommerce_product_get_name', array( __CLASS__, 'filter_text' ), 20, 1 );
			add_filter( 'woocommerce_product_variation_get_name', array( __CLASS__, 'filter_text' ), 20, 1 );
			add_filter( 'woocommerce_short_description', array( __CLASS__, 'filter_html' ), 20, 1 );
			add_filter( 'get_term', array( __CLASS__, 'filter_term' ), 20, 1 );
			add_filter( 'delicat_builder_v9_section_text', array( __CLASS__, 'filter_section' ), 20, 1 );
			add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'filter_menu' ), 20, 1 );
		}
	}

	private static function active(): bool {
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) ) {
			if ( is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) ) && Delicat_Builder_V9_Core::is_safe_mode() ) {
				return false;
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
				return false;
			}
		}
		return true;
	}

	private static function feature( string $name, bool $default = true ): bool {
		return (bool) apply_filters( 'delicat_builder_v9_app_polish_' . $name, $default );
	}

	/* ------------------------------------------------------------------ */
	/* Stylesheet                                                          */
	/* ------------------------------------------------------------------ */

	public static function assets(): void {
		if ( ! self::active() ) {
			return;
		}
		/* RC78: this read DELICAT_BUILDER_V9_PATH, a constant this plugin never
		 * defines — the directory constant is DELICAT_BUILDER_V9_DIR. The path
		 * was therefore always '', so file_exists() never ran (a missing asset
		 * was enqueued anyway and 404'd) and filemtime cache-busting never
		 * worked. Both are restored by using the real constant. */
		$base_path = defined( 'DELICAT_BUILDER_V9_DIR' ) ? DELICAT_BUILDER_V9_DIR : '';
		$base_url  = defined( 'DELICAT_BUILDER_V9_URL' ) ? DELICAT_BUILDER_V9_URL : '';
		if ( '' === $base_url ) {
			return;
		}
		$stamp = static function ( string $rel ) use ( $base_path ): string {
			$file = $base_path . $rel;
			return ( '' !== $base_path && file_exists( $file ) )
				? (string) filemtime( $file )
				: ( defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : '1' );
		};
		$shipped = static function ( string $rel ) use ( $base_path ): bool {
			return '' === $base_path || file_exists( $base_path . $rel );
		};

		if ( self::feature( 'styles' ) ) {
			$rel = 'assets/css/app-polish.css';
			if ( $shipped( $rel ) ) {
				wp_enqueue_style( self::HANDLE, $base_url . $rel, array(), $stamp( $rel ) );
			}
		}

		/* Pull down at the top of the page to reload — the browser's own gesture
		   is unavailable once overscroll is contained, and absent in the PWA. */
		if ( self::feature( 'pull_refresh', false ) ) {
			$rel = 'assets/js/pull-refresh.js';
			if ( $shipped( $rel ) ) {
				wp_enqueue_script( self::HANDLE . '-pull-refresh', $base_url . $rel, array(), $stamp( $rel ), true );
				if ( function_exists( 'wp_script_add_data' ) ) {
					wp_script_add_data( self::HANDLE . '-pull-refresh', 'strategy', 'defer' );
				}
			}
		}
	}


	/* ------------------------------------------------------------------ */
	/* Emoji sweep                                                         */
	/* ------------------------------------------------------------------ */

	/** @param mixed $text */
	public static function filter_text( $text ) {
		if ( ! is_string( $text ) || '' === $text || is_admin() ) {
			return $text;
		}
		if ( ! preg_match( self::pattern(), $text ) ) {
			return $text;
		}
		/* Removal only, never an SVG: the_title also feeds <title>, alt and
		 * aria-label attributes, cart lines and emails, where markup is wrong.
		 * Section headings get the icon through filter_section() instead. */
		try {
			return self::strip_only( $text );
		} catch ( Throwable $e ) {
			return $text;
		}
	}

	/**
	 * Section headings only. Receives text that is already escaped by the
	 * caller, and adds plugin-generated SVG — the only markup that can enter.
	 *
	 * @param mixed $text
	 */
	public static function filter_section( $text ) {
		if ( ! is_string( $text ) || '' === $text || is_admin() ) {
			return $text;
		}
		if ( ! preg_match( self::pattern(), $text ) ) {
			return $text;
		}
		try {
			return self::convert( $text );
		} catch ( Throwable $e ) {
			return $text;
		}
	}

	/** @param mixed $html */
	public static function filter_html( $html ) {
		if ( ! is_string( $html ) || '' === $html || is_admin() ) {
			return $html;
		}
		if ( ! preg_match( self::pattern(), $html ) ) {
			return $html;
		}
		try {
			return (string) preg_replace_callback(
				'#>([^<>]+)<#',
				static function ( array $m ): string {
					return '>' . self::strip_only( $m[1] ) . '<';
				},
				$html
			);
		} catch ( Throwable $e ) {
			return $html;
		}
	}

	/** @param mixed $term */
	public static function filter_term( $term ) {
		if ( $term instanceof WP_Term && ! is_admin() && isset( $term->name ) && is_string( $term->name ) ) {
			$term->name = (string) self::filter_text( $term->name );
		}
		return $term;
	}

	/** @param mixed $items */
	public static function filter_menu( $items ) {
		if ( ! is_array( $items ) || is_admin() ) {
			return $items;
		}
		foreach ( $items as $item ) {
			if ( is_object( $item ) && isset( $item->title ) && is_string( $item->title ) ) {
				$item->title = (string) self::filter_text( $item->title );
			}
		}
		return $items;
	}

	public static function start_buffer(): void {
		if ( ! self::active() || ! self::feature( 'emoji' ) || ! self::feature( 'document_emoji_sweep', false ) ) {
			return;
		}
		if ( is_feed() || is_robots() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( isset( $_GET['dbv9_fragment'] ) || isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}
		ob_start( array( __CLASS__, 'filter_document' ) );
	}

	/**
	 * @param string $html Full document.
	 * @return string
	 */
	public static function filter_document( $html ): string {
		$html = (string) $html;
		if ( '' === $html || false === stripos( ltrim( $html ), '<' ) ) {
			return $html;
		}
		if ( ! preg_match( '/<html[\s>]/i', $html ) ) {
			return $html;
		}
		/* RC78: the sweep costs four full-document regex passes plus a restore
		 * pass per protected block. One cheap scan first — a document with no
		 * emoji anywhere (the common case once content is clean) pays for that
		 * scan only and nothing else. */
		if ( ! preg_match( self::pattern(), $html ) ) {
			return $html;
		}
		try {
			return self::sweep( $html );
		} catch ( Throwable $error ) {
			unset( $error );
			return $html;
		}
	}

	private static function sweep( string $html ): string {
		self::$protected = array();

		/* 1. Freeze everything that must never be rewritten. */
		$html = (string) preg_replace_callback(
			'#<(script|style|svg|noscript|template)\b[^>]*>.*?</\1\s*>#is',
			array( __CLASS__, 'protect' ),
			$html
		);

		/* 2. Text-only elements: strip the emoji, never inject markup. */
		$html = (string) preg_replace_callback(
			'#<(title|option|textarea)\b[^>]*>.*?</\1\s*>#is',
			static function ( array $m ): string {
				return self::protect( array( 0 => self::strip_only( $m[0] ) ) );
			},
			$html
		);

		/* 3. Ordinary text nodes: map to icons, drop the rest. */
		$html = (string) preg_replace_callback(
			'#>([^<>]+)<#',
			static function ( array $m ): string {
				return '>' . self::convert( $m[1] ) . '<';
			},
			$html
		);

		/* 4. Restore.
		 *
		 * RC78: this was str_replace() with the full needle/replacement arrays,
		 * which walks the whole document once per protected block. A storefront
		 * page carries dozens of script/style blocks and a large number of
		 * inline icon SVGs, so the restore alone re-scanned the document that
		 * many times. One callback pass replaces every placeholder instead.
		 * The loop only runs again when a restored block itself contained a
		 * placeholder (a script inside a textarea and similar nesting), which
		 * the old ordered str_replace could leave unresolved. */
		if ( ! empty( self::$protected ) ) {
			$rounds = 0;
			while ( $rounds < 3 && false !== strpos( $html, '<!--dbv9-keep-' ) ) {
				$rounds++;
				$html = (string) preg_replace_callback(
					'/<!--dbv9-keep-\d+-\d+-->/',
					static function ( array $m ): string {
						return isset( self::$protected[ $m[0] ] ) ? (string) self::$protected[ $m[0] ] : '';
					},
					$html
				);
			}
		}
		self::$protected = array();
		return $html;
	}

	private static function protect( array $matches ): string {
		$key                     = '<!--dbv9-keep-' . count( self::$protected ) . '-' . wp_rand( 1000, 9999 ) . '-->';
		self::$protected[ $key ] = $matches[0];
		return $key;
	}

	/**
	 * RC78: three ranges were sweeping characters that are typography, not
	 * emoji, and since none of them has an icon token they were deleted from
	 * the page outright:
	 *
	 *   U+2190–U+21FF  arrows (→ ↗ ⇒ …) used in "voir tout" links and CTAs;
	 *   U+2460–U+24FF  circled numerals ① ② ③ used in the step lists;
	 *   U+2122         ™ — "PlayStation™" was shipping without its mark.
	 *
	 * They are removed from the pattern so the sweep never sees them. The
	 * emoji ranges are unchanged.
	 */
	private static function pattern(): string {
		return '/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{25A0}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{FE0E}\x{20E3}\x{3030}]/u';
	}

	/**
	 * Typographic symbols that are already text, not emoji: they inherit the
	 * font and color, so replacing them would be a regression (the review
	 * rating overlay, for one, measures the width of a run of stars).
	 *
	 * @return array<string,bool>
	 */
	private static function keep(): array {
		static $keep = null;
		if ( null === $keep ) {
			$keep = array_fill_keys(
				array(
					'★', '☆', '✓', '✔', '✕', '✖', '✦', '✧', '→', '←', '↑', '↓', '↔', '⟶', '⌚', '⌛', '№',
					/* RC78: dingbat arrows and bullets still inside U+25A0–U+27BF.
					 * They are list markers and CTA glyphs, not emoji. */
					'➜', '➔', '➤', '➡', '❯', '❮', '▪', '▫', '●', '○', '◆', '◇', '▸', '▹',
				),
				true
			);
		}
		return $keep;
	}

	public static function strip_only( string $text ): string {
		if ( ! preg_match( self::pattern(), $text ) ) {
			return $text;
		}
		$keep = self::keep();
		$text = (string) preg_replace_callback(
			self::pattern(),
			static function ( array $m ) use ( $keep ): string {
				return isset( $keep[ $m[0] ] ) ? (string) $m[0] : '';
			},
			$text
		);
		return self::tidy( $text );
	}

	public static function convert( string $text ): string {
		if ( '' === trim( $text ) || ! preg_match( self::pattern(), $text ) ) {
			return $text;
		}
		$map  = self::icon_map();
		$keep = self::keep();
		$text = (string) preg_replace_callback(
			self::pattern(),
			static function ( array $m ) use ( $map, $keep ): string {
				$char = (string) $m[0];
				if ( isset( $keep[ $char ] ) ) {
					return $char;
				}
				if ( isset( $map[ $char ] ) ) {
					return self::icon( $map[ $char ] );
				}
				return '';
			},
			$text
		);
		return self::tidy( $text );
	}

	private static function tidy( string $text ): string {
		$text = (string) preg_replace( '/[ \t]{2,}/u', ' ', $text );
		$text = (string) preg_replace( '/\x{00A0}{2,}/u', ' ', $text );
		/* "Rapide ·  " → "Rapide ·"; a dangling separator left by a removed emoji.
		 *
		 * RC78: this used to close up `;` `:` `!` and `?` as well, and matched
		 * `\s+` so it ate newlines too. French sets a space before those four
		 * marks — "Livraison instantanée : 24/7" was being rewritten to
		 * "Livraison instantanée: 24/7" on every line the sweep touched. Only
		 * the comma and full stop are closed up now, and only across spaces
		 * and tabs. */
		$text = (string) preg_replace( '/[ \t]+([,.])/u', '$1', $text );
		return $text;
	}

	/**
	 * Emoji → Builder icon token. Anything absent is simply removed, which is
	 * the wanted result for decorative repeats (🎉 💯 🙏 …).
	 *
	 * @return array<string,string>
	 */
	private static function icon_map(): array {
		return array(
			'🎮' => 'gamepad',
			'🕹' => 'gamepad',
			'🎁' => 'gift',
			'👑' => 'crown',
			'⚡' => 'bolt',
			'🔥' => 'flame',
			'💎' => 'diamond',
			'⭐' => 'star',
			'🌟' => 'star',
			'✨' => 'star',
			'💰' => 'coins',
			'💵' => 'coins',
			'💳' => 'card',
			'🛒' => 'cart',
			'🛍' => 'cart',
			'🔒' => 'lock',
			'🔐' => 'lock',
			'🛡' => 'shield',
			'📱' => 'phone',
			'🎧' => 'headset',
			'🌍' => 'globe',
			'🌏' => 'globe',
			'🌐' => 'globe',
			'🏷' => 'tag',
			'🏆' => 'trophy',
			'🎟' => 'ticket',
			'❤' => 'heart',
			'♥' => 'heart',
			'💜' => 'heart',
			'✅' => 'check',
			'🔎' => 'search',
			'🔍' => 'search',
			'📺' => 'tv',
			'▶' => 'play',
			'💬' => 'chat',
		);
	}

	private static function icon( string $token ): string {
		static $paths = null;
		if ( null === $paths ) {
			$paths = array(
				'gamepad' => '<rect x="2" y="7" width="20" height="11" rx="4"/><path d="M7 11v3M5.5 12.5h3M15.5 12h.01M18 14h.01"/>',
				'gift'    => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13M8 8a2.5 2.5 0 0 1 0-5c2 0 4 5 4 5s2-5 4-5a2.5 2.5 0 0 1 0 5"/>',
				'crown'   => '<path d="M3 8l4 4 5-7 5 7 4-4-2 12H5z"/>',
				'bolt'    => '<path d="M13 2 4.5 13.2h6.2L10 22l9-12h-6.3L13 2Z"/>',
				'flame'   => '<path d="M12 22a6 6 0 0 0 6-6c0-5-6-9-6-14 0 0-6 4-6 9a4 4 0 0 0 4 4 3 3 0 0 1-1 3 6 6 0 0 0 3 4Z"/>',
				'diamond' => '<path d="M12 3l9 7-9 11L3 10z"/><path d="M3 10h18"/>',
				'star'    => '<path d="M12 3l2.7 5.7 6.3.8-4.6 4.3 1.2 6.2L12 17l-5.6 3 1.2-6.2L3 9.5l6.3-.8z"/>',
				'coins'   => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
				'card'    => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
				'cart'    => '<circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M2 3h3l3 12h11l2-8H7"/>',
				'lock'    => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
				'shield'  => '<path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5z"/><path d="M9 11.5l2 2 4-4"/>',
				'phone'   => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/>',
				'headset' => '<path d="M4 13v-2a8 8 0 0 1 16 0v6h-4v-6h4M4 11v6h4v-6z"/><path d="M16 18h-4"/>',
				'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 4 6 4 9s-1 6-4 9c-3-3-4-6-4-9s1-6 4-9z"/>',
				'tag'     => '<path d="M3 4h8l10 10-7 7L4 11z"/><circle cx="8" cy="8" r="1.2"/>',
				'trophy'  => '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 3M16 6h3a3 3 0 0 1-3 3M10 17h4M9 21h6M12 13v4"/>',
				'ticket'  => '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v8a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2z"/><path d="M12 6v12"/>',
				'heart'   => '<path d="M12 20.5S4.5 15.9 2.7 11C1.6 7.6 3.6 4.5 6.9 4.5c1.9 0 3.4 1 4.3 2.4.9-1.4 2.4-2.4 4.3-2.4 3.3 0 5.3 3.1 4.2 6.5-1.8 4.9-9.3 9.5-9.3 9.5Z"/>',
				'check'   => '<path d="M4 12.5l5 5L20 6.5"/>',
				'search'  => '<circle cx="11" cy="11" r="7"/><path d="M16.5 16.5L21 21"/>',
				'tv'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M8 21h8"/>',
				'play'    => '<path d="M7 4l12 8-12 8z"/>',
				'arrow'   => '<path d="M4 12h15M13 6l6 6-6 6"/>',
				'chat'    => '<path d="M5 5h14v10H9l-4 4z"/>',
			);
		}
		$path = isset( $paths[ $token ] ) ? $paths[ $token ] : '';
		if ( '' === $path ) {
			return '';
		}
		return '<svg class="dbv9-emo" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}
}

Delicat_Builder_V9_App_Polish::boot();
