<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC79 — App Tuning.
 *
 * Two jobs, one module, because they answer the same question: what should the
 * storefront do when the phone is cheap and the connection is bad?
 *
 * 1. SLOW NETWORK. The storefront already stands down for `Save-Data: on` and
 *    for low memory / few cores. Neither of those fires for the common case in
 *    Haiti: a normal Android on a working but slow 3G link. Save-Data is off by
 *    default in Chrome and was removed entirely in newer versions, and a phone
 *    with 6 GB of RAM on a 3G tower reports as a fast device.
 *
 *    So the boot script also reads the connection itself: effectiveType 3g or
 *    worse, downlink under 1.5 Mbit/s, or round-trip over 300 ms sets
 *    `delicat-slow-net` on <html> before the first paint. Three things then
 *    change, and each is a request the phone no longer has to make:
 *
 *      - Web fonts never hold up the text. The Google Fonts request is given
 *        display=swap, so every visitor paints immediately in the system stack
 *        and the webfont swaps in when it arrives. (Until pro.16 this was done
 *        by shipping the stylesheet as media="print" and promoting it from
 *        JavaScript — a stylesheet that only applied because a script said so,
 *        which LiteSpeed's combiner could silently break. See
 *        defer_font_stylesheet().)
 *      - Speculation rules do not run. Prerendering the next page downloads a
 *        second full document over the same narrow pipe the shopper needs for
 *        the page in front of them. Worth it at 4G, a straight loss at 3G.
 *      - Decoration stands down through the existing low-power CSS.
 *
 *    All of it is client-side, so the cached HTML stays byte-identical for
 *    every guest and LiteSpeed keeps serving one copy.
 *
 * 2. APP SIZING. One `:root` block owns the storefront type scale, tap target
 *    floor, section rhythm and card widths, per breakpoint, from saved
 *    settings. On phones the scale is deliberately an h4 scale, not an h2
 *    one: the storefront reads as an app, and 26px section titles were eating
 *    a third of the fold. The markup level is unchanged — see scale() — because
 *    one cached document cannot serve a different tag per viewport.
 *
 *    Sizes are fixed pixel steps per breakpoint — no calc(), no
 *    clamp() — because LiteSpeed's CSS combiner strips the spaces those
 *    functions require and the browser then discards the whole declaration.
 *
 * Requires PHP 8.5 (gated in the main plugin file). Front end only. Stands down in safe mode.
 */
final class Delicat_Builder_V9_App_Tuning {

	const OPTION = 'delicat_builder_v9_app_tuning';
	const HANDLE = 'delicat-app-tuning';

	/**
	 * Every value is a plain pixel step. Mobile first: the phone numbers are
	 * the ones that matter here, the desktop numbers keep the wide layout
	 * unchanged from what RC78 shipped.
	 *
	 * @return array<string,int|string>
	 */
	public static function defaults(): array {
		return array(
			'enabled'         => 1,
			'slow_net'        => 1,
			'defer_fonts'     => 1,
			'gate_prerender'  => 1,

			/* Type scale — mobile / tablet / desktop, in px. */
			'h1_m'            => 27,
			'h1_t'            => 42,
			'h1_d'            => 52,
			'h2_m'            => 19,
			'h2_t'            => 30,
			'h2_d'            => 36,
			'h3_m'            => 15,
			'h3_t'            => 19,
			'h3_d'            => 21,
			'body_m'          => 14,
			'body_t'          => 15,
			'body_d'          => 16,
			'small_m'         => 13,
			'small_t'         => 13,
			'small_d'         => 14,

			/* Controls and rhythm. */
			'tap'             => 44,
			'cta_m'           => 46,
			'cta_d'           => 52,
			'radius_m'        => 16,
			'radius_d'        => 20,
			'section_gap_m'   => 14,
			'section_gap_t'   => 22,
			'section_gap_d'   => 40,
			'edge_m'          => 14,
			'edge_t'          => 20,
			'edge_d'          => 24,
			'card_m'          => 44,   /* card width as a % of the viewport on phones */
			'header_m'        => 60,
			'dock_m'          => 66,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
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
		$s = self::settings();
		return ! empty( $s['enabled'] );
	}

	private static function feature( string $name, bool $default = true ): bool {
		return (bool) apply_filters( 'delicat_builder_v9_app_tuning_' . $name, $default );
	}

	public static function boot(): void {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'boot_script' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'scale' ), 4 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 999 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'defer_font_stylesheet' ), 20, 4 );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Slow-network detection, before the first paint                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Deliberately tiny and inline: it has to run before the browser commits
	 * to fetching fonts, so it cannot be a file request of its own.
	 */
	public static function boot_script(): void {
		if ( ! self::active() || ! self::feature( 'slow_net' ) ) {
			return;
		}
		$s = self::settings();
		if ( empty( $s['slow_net'] ) ) {
			return;
		}
		?>
<script id="delicat-builder-v9-app-tuning-boot">
(function(){
var r=document.documentElement,n=navigator||{},
c=n.connection||n.mozConnection||n.webkitConnection||null,
t=c&&c.effectiveType?String(c.effectiveType).toLowerCase():'',
dl=c&&typeof c.downlink==='number'?c.downlink:0,
rt=c&&typeof c.rtt==='number'?c.rtt:0,
slow=!!(c&&c.saveData)||t==='slow-2g'||t==='2g'||t==='3g'||(dl>0&&dl<1.5)||(rt>300&&dl>0&&dl<3);
if(slow){r.className+=' delicat-slow-net delicat-low-power';}
r.setAttribute('data-dbv9-net',slow?'slow':'ok');
}());
</script>
		<?php
	}

	/**
	 * Give the Google Fonts request display=swap so the webfont never holds up
	 * the text. The HTML is the same for every visitor, so the page stays
	 * publicly cacheable, and no script is involved in making CSS apply.
	 *
	 * @param string $tag    Full link tag.
	 * @param string $handle Style handle.
	 * @param string $href   Stylesheet URL.
	 * @param string $media  Media attribute.
	 * @return string
	 */
	public static function defer_font_stylesheet( $tag, $handle, $href, $media ): string {
		$tag = (string) $tag;
		if ( ! self::active() || ! self::feature( 'defer_fonts' ) ) {
			return $tag;
		}
		$s = self::settings();
		if ( empty( $s['defer_fonts'] ) ) {
			return $tag;
		}
		$href = (string) $href;
		if ( false === strpos( $href, 'fonts.googleapis.com' ) ) {
			return $tag;
		}
		/*
		 * pro.16: this used to rewrite the font stylesheet to media="print" and let an
		 * inline boot script promote it back to media="all" on a fast connection. That
		 * is the one technique this codebase bans outright — a stylesheet that only
		 * applies because JavaScript made it apply — and the reason is on this exact
		 * stack: when LiteSpeed's guest combiner folds the link into its combined
		 * artefact the `link[data-dbv9-font]` selector stops matching and the fonts
		 * silently never load, for everyone, with the <noscript> fallback powerless to
		 * help because JavaScript is not what failed.
		 *
		 * The intent behind it was sound: do not let a webfont hold up first paint on a
		 * 3G link. CSS already has that, with no script and nothing for an optimizer to
		 * break — font-display: swap paints the text immediately in the fallback face
		 * and swaps when the webfont arrives. Google Fonts emits it when the request
		 * carries display=swap, so that is all this filter does now.
		 */
		$rewritten = preg_replace_callback(
			'/(href=(["\']))(.*?)\2/i',
			static function ( $match ) {
				$url = html_entity_decode( (string) $match[3], ENT_QUOTES, 'UTF-8' );
				if ( false === stripos( $url, 'fonts.googleapis.com' ) || false !== stripos( $url, 'display=' ) ) {
					return $match[0];
				}
				return $match[1] . esc_attr( add_query_arg( 'display', 'swap', $url ) ) . $match[2];
			},
			$tag,
			1
		);
		if ( is_string( $rewritten ) && '' !== $rewritten ) {
			$tag = $rewritten;
		}
		unset( $handle, $media );
		return $tag;
	}

	/* ------------------------------------------------------------------ */
	/* 2. One place for sizes and positions                                */
	/* ------------------------------------------------------------------ */

	public static function assets(): void {
		if ( ! self::active() || ! self::feature( 'styles' ) ) {
			return;
		}
		$dir = defined( 'DELICAT_BUILDER_V9_DIR' ) ? DELICAT_BUILDER_V9_DIR : '';
		$url = defined( 'DELICAT_BUILDER_V9_URL' ) ? DELICAT_BUILDER_V9_URL : '';
		$rel = 'assets/css/app-tuning.css';
		if ( '' === $url || ( '' !== $dir && ! file_exists( $dir . $rel ) ) ) {
			return;
		}
		$ver = ( '' !== $dir && file_exists( $dir . $rel ) )
			? (string) filemtime( $dir . $rel )
			: ( defined( 'DELICAT_BUILDER_V9_VERSION' ) ? DELICAT_BUILDER_V9_VERSION : '1' );
		wp_enqueue_style( self::HANDLE, $url . $rel, array(), $ver );
	}

	private static function px( array $s, string $key, int $min, int $max ): string {
		$value = isset( $s[ $key ] ) ? absint( $s[ $key ] ) : 0;
		if ( $value < $min ) {
			$value = $min;
		}
		if ( $value > $max ) {
			$value = $max;
		}
		return (string) $value . 'px';
	}

	/**
	 * Fixed pixel steps per breakpoint. No calc(), no clamp(): LiteSpeed's
	 * combiner strips the whitespace those functions require around + and -,
	 * and the browser then throws the whole declaration away silently.
	 */
	public static function scale(): void {
		if ( ! self::active() || ! self::feature( 'scale' ) ) {
			return;
		}
		$s = self::settings();

		$mobile = array(
			'--dbv9-app-h1:' . self::px( $s, 'h1_m', 20, 72 ),
			'--dbv9-app-h2:' . self::px( $s, 'h2_m', 16, 56 ),
			'--dbv9-app-h3:' . self::px( $s, 'h3_m', 13, 34 ),
			'--dbv9-app-body:' . self::px( $s, 'body_m', 12, 22 ),
			'--dbv9-app-small:' . self::px( $s, 'small_m', 11, 18 ),
			'--dbv9-app-radius:' . self::px( $s, 'radius_m', 0, 40 ),
			'--dbv9-app-gap:' . self::px( $s, 'section_gap_m', 6, 90 ),
			'--dbv9-app-edge:' . self::px( $s, 'edge_m', 0, 48 ),
			'--dbv9-app-cta:' . self::px( $s, 'cta_m', 36, 72 ),
			'--dbv9-app-header:' . self::px( $s, 'header_m', 44, 110 ),
			'--dbv9-app-dock:' . self::px( $s, 'dock_m', 48, 110 ),
			'--dbv9-app-tap:' . self::px( $s, 'tap', 36, 64 ),
			'--dbv9-app-card:' . (string) max( 28, min( 92, absint( $s['card_m'] ) ) ) . 'vw',
		);
		$tablet = array(
			'--dbv9-app-h1:' . self::px( $s, 'h1_t', 20, 84 ),
			'--dbv9-app-h2:' . self::px( $s, 'h2_t', 16, 64 ),
			'--dbv9-app-h3:' . self::px( $s, 'h3_t', 13, 38 ),
			'--dbv9-app-body:' . self::px( $s, 'body_t', 12, 24 ),
			'--dbv9-app-small:' . self::px( $s, 'small_t', 11, 20 ),
			'--dbv9-app-gap:' . self::px( $s, 'section_gap_t', 6, 120 ),
			/*
			 * pro.17: the tablet tier defined no page edge, so 641-960px kept the
			 * phone's 14px gutter and the homepage ran almost to the bezel before
			 * jumping to 24px at 961px. Give the tier its own step.
			 */
			'--dbv9-app-edge:' . self::px( $s, 'edge_t', 0, 64 ),
		);
		$desktop = array(
			'--dbv9-app-h1:' . self::px( $s, 'h1_d', 20, 110 ),
			'--dbv9-app-h2:' . self::px( $s, 'h2_d', 16, 80 ),
			'--dbv9-app-h3:' . self::px( $s, 'h3_d', 13, 46 ),
			'--dbv9-app-body:' . self::px( $s, 'body_d', 12, 26 ),
			'--dbv9-app-small:' . self::px( $s, 'small_d', 11, 22 ),
			'--dbv9-app-radius:' . self::px( $s, 'radius_d', 0, 48 ),
			'--dbv9-app-gap:' . self::px( $s, 'section_gap_d', 6, 160 ),
			'--dbv9-app-edge:' . self::px( $s, 'edge_d', 0, 80 ),
			'--dbv9-app-cta:' . self::px( $s, 'cta_d', 36, 80 ),
		);

		$css  = ':root{' . implode( ';', $mobile ) . '}';
		$css .= '@media(min-width:641px){:root{' . implode( ';', $tablet ) . '}}';
		$css .= '@media(min-width:961px){:root{' . implode( ';', $desktop ) . '}}';

		echo '<style id="delicat-builder-v9-app-scale">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is absint-clamped and suffixed above.
	}
}

Delicat_Builder_V9_App_Tuning::boot();
