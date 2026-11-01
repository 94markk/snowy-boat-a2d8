<?php
/**
 * Delicat Design System 2.0 — the single source of design tokens.
 *
 * Every colour, font, radius, spacing step, shadow and duration used by the
 * storefront, Delicat Identity and Delicat Recharge comes from here. The
 * legacy namespaces (--dlc-*, --delicat-*, --dipma-*, --dr-*, ...) alias these
 * tokens instead of defining their own values, so one change here (or in the
 * Design Studio settings) reaches every page.
 *
 * The tokens are printed inline at wp_head priority 0 — before the pre-paint
 * theme boot (priority 1) and before any stylesheet — so the first paint is
 * already correct in light and dark mode and no extra request blocks rendering.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Design_System {

	/** Design Studio overrides (sanitised hex colours only). */
	public const OPTION = 'delicat_builder_v9_design_system';

	/** Self-hosted Inter (variable weight), Latin first: the only file preloaded. */
	private const FONT_FILES = array(
		'latin'     => 'assets/fonts/inter/inter-latin-wght-normal.woff2',
		'latin-ext' => 'assets/fonts/inter/inter-latin-ext-wght-normal.woff2',
	);

	private const UNICODE_RANGES = array(
		'latin'     => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
		'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
	);

	/** Colour tokens that the Design Studio may override. */
	private const OVERRIDABLE = array( 'primary', 'primary-hover', 'brand', 'background', 'surface', 'text' );

	private static ?string $css = null;

	public static function boot(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 0 );
		add_action( 'login_head', array( __CLASS__, 'print_head' ), 0 );
		/* Other plugins and standalone documents can ask for the same tokens. */
		add_action( 'delicat_ds_print_tokens', array( __CLASS__, 'print_head' ) );
	}

	/**
	 * Light and dark values. Contrast is checked against WCAG 2.2 AA:
	 * text 4.5:1 on every surface it sits on, input borders and focus 3:1.
	 *
	 * @return array{light: array<string,string>, dark: array<string,string>}
	 */
	public static function colors(): array {
		$light = array(
			'primary'          => '#2563EB', // 5.17:1 on white, white on it 5.17:1
			'primary-hover'    => '#1D4ED8',
			'primary-subtle'   => '#EFF6FF',
			'primary-contrast' => '#FFFFFF',
			'brand'            => '#00004F', // logo navy ("STORE HAITI")
			'brand-contrast'   => '#FFFFFF',
			'background'       => '#F7F9FC',
			'surface'          => '#FFFFFF',
			'surface-2'        => '#F1F5F9',
			'surface-elevated' => '#FFFFFF',
			'text'             => '#111827',
			'text-muted'       => '#5F6B7E', // >= 4.93:1 on background, surface and surface-2
			'border'           => '#E2E8F0', // decorative separators
			'border-strong'    => '#7F8A9C', // inputs and controls, >= 3:1
			'success'          => '#16A34A', // fills and icons
			'success-text'     => '#15803D', // text, 5.02:1
			'warning'          => '#F59E0B',
			'warning-text'     => '#B45309', // 5.02:1
			'danger'           => '#DC2626', // 4.83:1
			'danger-text'      => '#DC2626',
			'focus'            => '#2563EB',
			'overlay'          => 'rgba(15,23,42,.48)',
		);
		$dark = array(
			'primary'          => '#60A5FA', // 6.98:1 on surface
			'primary-hover'    => '#93C5FD',
			'primary-subtle'   => '#172554',
			'primary-contrast' => '#0B1220', // 7.36:1 on primary
			'brand'            => '#C7D2FE', // navy turns into a light ink on dark surfaces
			'brand-contrast'   => '#090F1D',
			'background'       => '#090F1D',
			'surface'          => '#111827',
			'surface-2'        => '#182235',
			'surface-elevated' => '#182235',
			'text'             => '#F8FAFC',
			'text-muted'       => '#94A3B8', // >= 6.21:1
			'border'           => '#293548',
			'border-strong'    => '#64748B', // >= 3.35:1
			'success'          => '#22C55E',
			'success-text'     => '#22C55E',
			'warning'          => '#FBBF24',
			'warning-text'     => '#FBBF24',
			'danger'           => '#F87171',
			'danger-text'      => '#F87171',
			'focus'            => '#60A5FA',
			'overlay'          => 'rgba(0,0,0,.6)',
		);

		$saved = get_option( self::OPTION, array() );
		if ( is_array( $saved ) ) {
			foreach ( self::OVERRIDABLE as $key ) {
				foreach ( array( 'light', 'dark' ) as $mode ) {
					$value = $saved[ $mode ][ $key ] ?? '';
					if ( ! is_string( $value ) || ! preg_match( '/^#[0-9a-f]{6}$/i', $value ) ) {
						continue;
					}
					if ( 'light' === $mode ) {
						$light[ $key ] = strtoupper( $value );
					} else {
						$dark[ $key ] = strtoupper( $value );
					}
				}
			}
		}

		$tokens = apply_filters( 'delicat_ds_colors', array( 'light' => $light, 'dark' => $dark ) );
		return is_array( $tokens ) && isset( $tokens['light'], $tokens['dark'] ) ? $tokens : array( 'light' => $light, 'dark' => $dark );
	}

	/** Theme-independent tokens: type, spacing, radii, motion, layout. */
	public static function scale(): array {
		return array(
			'font-sans'            => '"Inter",system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif',
			'font-mono'            => 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
			'font-weight-regular'  => '400',
			'font-weight-medium'   => '500',
			'font-weight-semibold' => '600',
			'font-weight-bold'     => '700',
			// Mobile-first type scale; components step up on desktop.
			'text-meta'            => '12px',
			'text-secondary'       => '13px',
			'text-body'            => '15px',
			'text-button'          => '15px',
			'text-card-title'      => '15px',
			'text-section'         => '21px',
			'text-page'            => '28px',
			'leading-tight'        => '1.2',
			'leading-body'         => '1.5',
			'space-1'              => '4px',
			'space-2'              => '8px',
			'space-3'              => '12px',
			'space-4'              => '16px',
			'space-5'              => '20px',
			'space-6'              => '24px',
			'space-8'              => '32px',
			'space-10'             => '40px',
			'space-12'             => '48px',
			'space-16'             => '64px',
			'space-page'           => '16px',
			'radius-sm'            => '8px',
			'radius-md'            => '12px',
			'radius-lg'            => '16px',
			'radius-xl'            => '20px',
			'radius-2xl'           => '24px',
			'radius-full'          => '999px',
			'duration-press'       => '110ms',
			'duration-tab'         => '150ms',
			'duration-page'        => '180ms',
			'duration-modal'       => '200ms',
			'ease'                 => 'cubic-bezier(.2,.8,.2,1)',
			'content-max'          => '1200px',
			'tap-target'           => '44px',
			'icon-sm'              => '18px',
			'icon-md'              => '20px',
			'icon-lg'              => '24px',
		);
	}

	/** Shadows differ per theme (dark surfaces need deeper, darker shadows). */
	private static function shadows(): array {
		return array(
			'light' => array(
				'shadow-1' => '0 1px 2px rgba(15,23,42,.06)',
				'shadow-2' => '0 4px 14px rgba(15,23,42,.08)',
				'shadow-3' => '0 12px 32px rgba(15,23,42,.14)',
			),
			'dark'  => array(
				'shadow-1' => '0 1px 2px rgba(0,0,0,.4)',
				'shadow-2' => '0 4px 14px rgba(0,0,0,.45)',
				'shadow-3' => '0 12px 32px rgba(0,0,0,.55)',
			),
		);
	}

	/** The complete stylesheet (tokens + Inter @font-face), built once per request. */
	public static function css(): string {
		if ( null !== self::$css ) {
			return self::$css;
		}
		$colors  = self::colors();
		$shadows = self::shadows();

		$decl = static function ( array $map, string $prefix ): string {
			$out = '';
			foreach ( $map as $name => $value ) {
				$out .= '--ds-' . $prefix . $name . ':' . $value . ';';
			}
			return $out;
		};

		$light = $decl( $colors['light'], 'color-' ) . $decl( $shadows['light'], '' ) . 'color-scheme:light;';
		$dark  = $decl( $colors['dark'], 'color-' ) . $decl( $shadows['dark'], '' ) . 'color-scheme:dark;';

		$css  = ':root{' . $decl( self::scale(), '' ) . '}';
		$css .= ':root,html[data-delicat-theme="light"]{' . $light . '}';
		$css .= 'html[data-delicat-theme="dark"],html.dlc-theme-dark{' . $dark . '}';
		/* No pre-paint script (JS off, standalone documents): follow the device. */
		$css .= '@media (prefers-color-scheme:dark){html:not([data-delicat-theme]):not(.dlc-theme-light){' . $dark . '}}';
		/* 9.7.0: Material Symbols are filled glyphs. Older component sheets style every svg as a 2 px
		 * outline (fill:none; stroke:…); the icon primitive must win over those rules. */
		$css .= 'svg.ds-icon,svg.ds-icon *{fill:currentColor!important;stroke:none!important}svg.ds-icon{flex:none}';

		foreach ( self::FONT_FILES as $subset => $file ) {
			if ( ! file_exists( DELICAT_BUILDER_V9_DIR . $file ) ) {
				continue;
			}
			$url  = esc_url( DELICAT_BUILDER_V9_URL . $file . '?ver=' . DELICAT_BUILDER_V9_VERSION );
			$css .= '@font-face{font-family:"Inter";font-style:normal;font-weight:100 900;font-display:optional;'
				. 'src:url(' . $url . ') format("woff2");unicode-range:' . self::UNICODE_RANGES[ $subset ] . '}';
		}

		self::$css = $css;
		return self::$css;
	}

	public static function print_head(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		$latin   = self::FONT_FILES['latin'];
		if ( file_exists( DELICAT_BUILDER_V9_DIR . $latin ) ) {
			printf(
				'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
				esc_url( DELICAT_BUILDER_V9_URL . $latin . '?ver=' . DELICAT_BUILDER_V9_VERSION )
			);
		}
		// Values are fixed strings or #RRGGBB checked in colors(); nothing user-supplied reaches the markup.
		echo '<style id="delicat-ds-tokens">' . self::css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/** @var array<string,string>|null Material Symbols inner markup, loaded on first use. */
	private static ?array $icons = null;

	/**
	 * The one icon set: Material Symbols Rounded (weight 400), drawn in currentColor so
	 * icons follow the text colour in light and dark mode. Names use hyphens
	 * ('account-balance-wallet'); '-fill' variants mark active states. Decorative by
	 * default (aria-hidden); pass 'label' for an icon that is the only content of a control.
	 *
	 * @param array{label?:string,class?:string} $args
	 */
	public static function icon( string $name, int $size = 20, array $args = array() ): string {
		$icons = self::icons();
		$name  = str_replace( '_', '-', sanitize_key( $name ) );
		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}
		$size  = max( 12, min( 48, $size ) );
		$class = 'ds-icon ds-icon--' . $name . ( empty( $args['class'] ) ? '' : ' ' . sanitize_html_class( (string) $args['class'] ) );
		$a11y  = empty( $args['label'] )
			? 'aria-hidden="true" focusable="false"'
			: 'role="img" aria-label="' . esc_attr( (string) $args['label'] ) . '"';
		// Inner markup is the fixed Material Symbols set shipped with the plugin.
		return '<svg class="' . esc_attr( $class ) . '" width="' . $size . '" height="' . $size . '" viewBox="0 -960 960 960" fill="currentColor" ' . $a11y . '>' . $icons[ $name ] . '</svg>';
	}

	/**
	 * The same icon as a CSS `url("data:…")` value, for `mask-image` on markup the plugin does not
	 * own (WooCommerce menus). Paint it with `background: currentColor`. Empty string if unknown.
	 */
	public static function icon_mask( string $name ): string {
		$icons = self::icons();
		$name  = str_replace( '_', '-', sanitize_key( $name ) );
		if ( ! isset( $icons[ $name ] ) ) {
			return '';
		}
		$svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 -960 960 960'>" . str_replace( '"', "'", $icons[ $name ] ) . '</svg>';
		return 'url("data:image/svg+xml,' . str_replace( array( '%', '#', '<', '>', '"' ), array( '%25', '%23', '%3C', '%3E', '%22' ), $svg ) . '")';
	}

	/** @return array<string,string> Inner SVG markup keyed by icon name, loaded once per request. */
	private static function icons(): array {
		if ( null === self::$icons ) {
			$file        = DELICAT_BUILDER_V9_DIR . 'includes/lib/icons-material.php';
			$map         = file_exists( $file ) ? require $file : array();
			self::$icons = is_array( $map ) ? $map : array();
		}
		return self::$icons;
	}

	/** Machine-readable token set, for the Design Studio export and design-tokens.json. */
	public static function export(): array {
		return array(
			'name'    => 'Delicat Design System',
			'version' => '2.0',
			'colors'  => self::colors(),
			'shadows' => self::shadows(),
			'scale'   => self::scale(),
		);
	}
}

if ( ! function_exists( 'delicat_ds_icon' ) ) {
	/**
	 * Shared icon helper for the Builder, Delicat Identity and Delicat Recharge.
	 * Callers in other plugins should check function_exists( 'delicat_ds_icon' ).
	 */
	function delicat_ds_icon( string $name, int $size = 20, array $args = array() ): string {
		return Delicat_Builder_V9_Design_System::icon( $name, $size, $args );
	}
}
