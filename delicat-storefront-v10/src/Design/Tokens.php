<?php
namespace Delicat\V10\Design;

use Delicat\V10\Compat\V9Options;
use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * THE source of truth for every design constant in V10.
 *
 * -----------------------------------------------------------------------------
 * The rule this class exists to enforce
 * -----------------------------------------------------------------------------
 * No number and no colour is written anywhere else. Not in a stylesheet, not in
 * a template, not in an inline style. CSS reads var(--dlx-*). PHP reads
 * Tokens::get(). There is one place to change a value and one value to change.
 *
 * V9's storefront held fifteen independent opinions about how tall the bottom
 * bars are - 0, 82, 86, 88, 92, 96, 104, 164, 172, 178, 180, 190 and 196px,
 * spread across nine stylesheets and an inline block - and which one applied was
 * decided by how many class names each rule happened to carry. The bar is 106.
 * The homepage reserved 96, so the bar covered the product cards.
 *
 * That is not fixable by picking the right number in fifteen places. It is
 * fixable by there being one place. Below, the occluded band is *derived* from
 * the tab bar's own height and the device's safe area, so it cannot disagree
 * with the thing it is measuring.
 */
final class Tokens extends Module {

	/** Emitted before any stylesheet, so no stylesheet can win against it. */
	public static function priority(): int {
		return 1;
	}

	public static function kinds(): array {
		return array( Context::KIND_FRONT, Context::KIND_ADMIN );
	}

	/** @var array<string,array<string,mixed>>|null */
	private static $resolved = null;

	public function register(): void {
		/* Priority 2: after <meta charset> and the theme colour, before every
		 * stylesheet link, so the properties exist when the first rule that
		 * reads them is parsed. */
		add_action( 'wp_head', array( $this, 'print_root' ), 2 );
		add_action( 'delicat_v10_settings_saved', array( __CLASS__, 'flush' ) );
	}

	/* ---------------------------------------------------------------------
	 * The tokens themselves
	 * ------------------------------------------------------------------- */

	/**
	 * Structure: group => key => value.
	 *
	 * Values are raw - numbers are pixels, colours are hex - so PHP can do
	 * arithmetic on them. Units are attached once, at emit time.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function defaults(): array {
		return array(

			/* ---- Colour: one ramp, stated once, restated once for dark ---- */
			'color' => array(
				'brand'       => '#6d5dfc',
				'brand-ink'   => '#ffffff',
				'accent'      => '#ec4899',
				'positive'    => '#0f7b4a',
				'warning'     => '#9a5b04',
				'danger'      => '#c41840',

				'ground'      => '#f5f6fb',
				'surface'     => '#ffffff',
				'raised'      => '#ffffff',
				'sunken'      => '#eef0f7',
				'line'        => '#e4e7f1',
				'line-soft'   => '#eff1f7',

				'ink'         => '#111827',
				'ink-soft'    => '#3d4558',
				'ink-muted'   => '#6b7280',
				'ink-inverse' => '#ffffff',

				/*
				 * Text and scrim over a photograph. These two are the same in
				 * both themes on purpose: the contrast they need comes from the
				 * image underneath, not from the page, so flipping them with the
				 * theme would make one of the two unreadable.
				 */
				'on-media'    => '#ffffff',
				'scrim'       => 'rgb(8 10 20 / 0.45)',
				'scrim-deep'  => 'rgb(8 10 20 / 0.72)',
			),

			/*
			 * Dark is a re-statement of the same keys, never a set of overrides
			 * scattered through the components. A component that reads
			 * var(--dlx-color-surface) is correct in both themes with no
			 * dark-mode rule of its own - which is why V10 ships none, and why
			 * the class of bug that silently disabled 25 of V9's dark rules
			 * has nothing to break here.
			 */
			'color-dark' => array(
				'brand'       => '#8b7cff',
				'brand-ink'   => '#0b0d16',
				'accent'      => '#ff6cb5',
				'positive'    => '#4ecf8f',
				'warning'     => '#e8a94f',
				'danger'      => '#ff7b96',

				'ground'      => '#0b0d16',
				'surface'     => '#141726',
				'raised'      => '#1b1f31',
				'sunken'      => '#0f1220',
				'line'        => '#262b3d',
				'line-soft'   => '#1c2030',

				'ink'         => '#eef1f8',
				'ink-soft'    => '#c3cadc',
				'ink-muted'   => '#8f98b0',
				'ink-inverse' => '#0b0d16',

				'on-media'    => '#ffffff',
				'scrim'       => 'rgb(8 10 20 / 0.45)',
				'scrim-deep'  => 'rgb(8 10 20 / 0.72)',
			),

			/* ---- Space: a 4px scale. Nothing uses a value off this scale. ---- */
			'space' => array(
				'2xs' => 4,
				'xs'  => 8,
				'sm'  => 12,
				'md'  => 16,
				'lg'  => 24,
				'xl'  => 32,
				'2xl' => 48,
				'3xl' => 64,
			),

			/*
			 * The page gutter, per tier - all three stated. V9 defined phone
			 * and desktop and let tablet inherit the phone value, so a 768px
			 * tablet ran its content almost to the bezel. A tier that is not
			 * stated is a tier that is wrong.
			 */
			'gutter' => array(
				'phone'   => 16,
				'tablet'  => 24,
				'desktop' => 32,
			),

			'radius' => array(
				'sm'   => 10,
				'md'   => 14,
				'lg'   => 20,
				'xl'   => 28,
				'full' => 999,
			),

			/* ---- Type: one ramp, fluid between its phone and desktop ends ---- */
			'text' => array(
				'xs'  => array( 12, 13 ),
				'sm'  => array( 13, 14 ),
				'md'  => array( 15, 16 ),
				'lg'  => array( 17, 19 ),
				'xl'  => array( 20, 24 ),
				'2xl' => array( 24, 32 ),
				'3xl' => array( 28, 44 ),
			),

			/* ---- Chrome geometry. Everything about the bars derives from these. ---- */
			'chrome' => array(
				'header'     => 60,
				'tabbar'     => 64,
				'dock'       => 76,
				/*
				 * How far the tab bar floats above the bottom edge. Zero on a
				 * phone, where the bar is edge to edge; a gap on a tablet,
				 * where it is a centred pill.
				 *
				 * This is a token because it is part of how much of the screen
				 * the bar occupies, and anything sitting on the bar has to know
				 * it. When it was written directly into the tablet rule the
				 * dock overlapped the bar by exactly these 12 pixels at 768px -
				 * caught by a browser measurement, but only because one was
				 * taken. Derived, it cannot happen again.
				 */
				'tabbar-float'   => 0,
				'tabbar-float-t' => 12,
				'tablet-at'  => 720,
				/* The tab bar becomes a centred pill at this width, which is
				 * narrower than the tablet type tier: a 560px phone in
				 * landscape already wants a thumb-reachable bar. */
				'float-at'   => 560,
				'desktop-at' => 1024,
			),

			/* ---- Motion: one duration scale, honoured centrally ---- */
			'motion' => array(
				'instant' => 90,
				'quick'   => 160,
				'base'    => 240,
				'slow'    => 380,
			),

			'ease' => array(
				'out'    => 'cubic-bezier(.32,.72,0,1)',
				'in-out' => 'cubic-bezier(.65,0,.35,1)',
			),

			'shadow' => array(
				'sm' => '0 1px 2px rgba(16,24,40,.06)',
				'md' => '0 4px 16px rgba(16,24,40,.08)',
				'lg' => '0 12px 40px rgba(16,24,40,.12)',
			),

			/* ---- Stacking: named, ordered, and the only z-indexes in V10 ---- */
			'layer' => array(
				'base'   => 0,
				'raised' => 10,
				'sticky' => 100,
				'header' => 400,
				'tabbar' => 420,
				'dock'   => 440,
				'scrim'  => 600,
				'drawer' => 620,
				'sheet'  => 640,
				'toast'  => 800,
			),
		);
	}

	/**
	 * Defaults merged with what the merchant has configured, including the V9
	 * settings they already had - so upgrading does not reset the store's
	 * appearance to a stranger's idea of it.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$tokens = V9Options::apply_to_tokens( self::defaults() );

		$saved = get_option( 'delicat_v10_tokens', array() );
		if ( is_array( $saved ) ) {
			foreach ( $saved as $group => $values ) {
				if ( isset( $tokens[ $group ] ) && is_array( $values ) ) {
					$tokens[ $group ] = array_merge( $tokens[ $group ], $values );
				}
			}
		}

		/**
		 * Last word on every design constant in the storefront.
		 *
		 * @param array<string,array<string,mixed>> $tokens
		 */
		$tokens = apply_filters( 'delicat_v10_tokens', $tokens );

		self::$resolved = is_array( $tokens ) ? $tokens : self::defaults();
		return self::$resolved;
	}

	/**
	 * One token, for PHP that needs the same number the CSS is using.
	 *
	 * @param mixed $fallback
	 * @return mixed
	 */
	public static function get( string $group, string $key, $fallback = null ) {
		$all = self::all();
		return $all[ $group ][ $key ] ?? $fallback;
	}

	public static function flush(): void {
		self::$resolved = null;
	}

	/* ---------------------------------------------------------------------
	 * Emission
	 * ------------------------------------------------------------------- */

	public function print_root(): void {
		$css = self::css();
		if ( '' === $css ) {
			return;
		}
		/*
		 * Inline on purpose, not enqueued. An optimiser that defers, combines
		 * or reorders stylesheets could otherwise make the tokens arrive after
		 * the rules that read them, and every var() would fall back at once.
		 * Inline in <head> cannot be reordered.
		 */
		echo '<style id="delicat-v10-tokens">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from the sanitisers below.
	}

	/**
	 * The whole token layer as CSS. A pure function of all(): no request state,
	 * so it is byte-identical for every visitor and safe in a shared cache.
	 */
	public static function css(): string {
		$t = self::all();

		$root = array();

		foreach ( $t['color'] as $key => $value ) {
			$root[] = '--dlx-' . self::slug( $key ) . ':' . self::color( $value );
		}
		foreach ( $t['space'] as $key => $value ) {
			$root[] = '--dlx-space-' . self::slug( $key ) . ':' . self::px( $value );
		}
		foreach ( $t['radius'] as $key => $value ) {
			$root[] = '--dlx-radius-' . self::slug( $key ) . ':' . self::px( $value );
		}
		foreach ( $t['shadow'] as $key => $value ) {
			$root[] = '--dlx-shadow-' . self::slug( $key ) . ':' . self::shadow( (string) $value );
		}
		foreach ( $t['layer'] as $key => $value ) {
			$root[] = '--dlx-z-' . self::slug( $key ) . ':' . (int) $value;
		}
		foreach ( $t['motion'] as $key => $value ) {
			$root[] = '--dlx-t-' . self::slug( $key ) . ':' . (int) $value . 'ms';
		}
		foreach ( $t['ease'] as $key => $value ) {
			$root[] = '--dlx-ease-' . self::slug( $key ) . ':' . self::easing( (string) $value );
		}

		/*
		 * Type scale. Each step interpolates between its phone and desktop size
		 * across the viewport, so text never jumps at a breakpoint. clamp() is
		 * written with the spaces its grammar requires around + ; a minifier
		 * that removes them yields an invalid value and the browser drops the
		 * whole declaration. That is precisely how V9 lost 25 dark-theme rules,
		 * so build/build.mjs asserts the spacing survives the bundle.
		 */
		foreach ( $t['text'] as $key => $pair ) {
			$min    = (float) ( is_array( $pair ) ? $pair[0] : $pair );
			$max    = (float) ( is_array( $pair ) ? $pair[1] : $pair );
			$root[] = '--dlx-text-' . self::slug( $key ) . ':' . self::fluid( $min, $max );
		}

		$chrome = $t['chrome'];
		$gutter = $t['gutter'];

		$root[] = '--dlx-gutter:' . self::px( $gutter['phone'] );
		$root[] = '--dlx-safe-t:env(safe-area-inset-top, 0px)';
		$root[] = '--dlx-safe-b:env(safe-area-inset-bottom, 0px)';
		$root[] = '--dlx-header-h:' . self::px( $chrome['header'] );
		$root[] = '--dlx-tabbar-h:' . self::px( $chrome['tabbar'] );
		$root[] = '--dlx-tabbar-float:' . self::px( $chrome['tabbar-float'] );
		$root[] = '--dlx-dock-h:' . self::px( $chrome['dock'] );

		/*
		 * The total vertical space the tab bar occupies, float and safe area
		 * included. Everything that sits on the bar or clears it reads THIS,
		 * not its parts, so a change to any part reaches all of them at once.
		 */
		$root[] = '--dlx-tabbar-block:calc(var(--dlx-tabbar-h) + var(--dlx-tabbar-float) + var(--dlx-safe-b))';

		/*
		 * The occluded band: how much of the bottom of the viewport is covered
		 * by fixed chrome right now. Derived, never typed.
		 *
		 * Base is zero - a page with no bars reserves nothing. The tab bar
		 * raises it by exactly its own height plus the device safe area. A
		 * product dock raises it again by exactly the dock's height. Anything
		 * that needs clearance writes padding-bottom: var(--dlx-band) and is
		 * correct at every width, in every combination, permanently.
		 */
		$root[] = '--dlx-band:0px';

		$css = ':root{' . implode( ';', $root ) . '}';

		$dark = array();
		foreach ( $t['color-dark'] as $key => $value ) {
			$dark[] = '--dlx-' . self::slug( $key ) . ':' . self::color( $value );
		}
		$dark = implode( ';', $dark );

		/*
		 * Three selectors, because a theme has three states and all three must
		 * be stated: an explicit dark choice, an explicit light choice, and no
		 * choice at all. The media query is guarded so that choosing light on a
		 * dark device wins; the attribute rule comes last so that choosing dark
		 * on a light device wins too.
		 */
		$css .= '@media (prefers-color-scheme:dark){:root:not([data-dlx-theme="light"]){' . $dark . '}}';
		$css .= ':root[data-dlx-theme="dark"]{' . $dark . '}';

		$css .= '@media (min-width:' . (int) $chrome['float-at'] . 'px){:root{--dlx-tabbar-float:' . self::px( $chrome['tabbar-float-t'] ) . '}}';
		$css .= '@media (min-width:' . (int) $chrome['tablet-at'] . 'px){:root{--dlx-gutter:' . self::px( $gutter['tablet'] ) . '}}';
		$css .= '@media (min-width:' . (int) $chrome['desktop-at'] . 'px){:root{--dlx-gutter:' . self::px( $gutter['desktop'] ) . '}}';

		/*
		 * One honest statement about motion, here, instead of a
		 * prefers-reduced-motion block in every component. Durations collapse
		 * and everything downstream keeps working unchanged - including the
		 * view transitions, which read these same properties.
		 */
		$css .= '@media (prefers-reduced-motion:reduce){:root{--dlx-t-instant:1ms;--dlx-t-quick:1ms;--dlx-t-base:1ms;--dlx-t-slow:1ms}}';

		return $css;
	}

	/* ---------------------------------------------------------------------
	 * Sanitisation. Everything printed passes through one of these.
	 * ------------------------------------------------------------------- */

	private static function slug( string $key ): string {
		return strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $key ) );
	}

	/** @param mixed $value */
	private static function px( $value ): string {
		return rtrim( rtrim( number_format( (float) $value, 2, '.', '' ), '0' ), '.' ) . 'px';
	}

	/** @param mixed $value */
	private static function color( $value ): string {
		$value = trim( (string) $value );
		if ( preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ) {
			return $value;
		}
		if ( preg_match( '#^(?:rgb|hsl|hwb|oklch|oklab|lab|lch)a?\([0-9a-z%.,/\s+-]*\)$#i', $value ) ) {
			return $value;
		}
		return '#000000';
	}

	private static function shadow( string $value ): string {
		return (string) preg_replace( '#[^0-9a-z%.,()/\s-]#i', '', $value );
	}

	private static function easing( string $value ): string {
		return (string) preg_replace( '/[^0-9a-z%.,()\s-]/i', '', $value );
	}

	/**
	 * A size that grows with the viewport between two fixed ends, interpolated
	 * across 360px..1280px. Written out here, once, so there is one place to
	 * verify the operator spacing clamp() requires.
	 */
	private static function fluid( float $min, float $max ): string {
		if ( $max <= $min ) {
			return self::px( $min );
		}

		$slope     = ( $max - $min ) / ( 1280 - 360 );
		$intercept = $min - ( $slope * 360 );

		return sprintf(
			'clamp(%spx, %spx + %svw, %spx)',
			self::num( $min ),
			self::num( $intercept ),
			self::num( $slope * 100 ),
			self::num( $max )
		);
	}

	private static function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
	}
}
