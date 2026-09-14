<?php
namespace Delicat\V10\Shell;

defined( 'ABSPATH' ) || exit;

/**
 * The icon set, inline.
 *
 * Inline SVG rather than an icon font or a sprite sheet, for three reasons that
 * all matter on a phone: there is no second request, the glyph inherits
 * currentColor so it is correct in both themes with no second asset, and it
 * cannot arrive late and shift the layout.
 *
 * Every path is drawn on a 24x24 grid with a 1.75 stroke so the set looks like
 * one set. V9 mixed three icon sources at three weights.
 */
final class Icons {

	/** @var array<string,string> */
	private const PATHS = array(
		'home'     => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20a1 1 0 0 0 1 1H10v-5.5h4V21h3.5a1 1 0 0 0 1-1V9.5"/>',
		'grid'     => '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>',
		'cart'     => '<path d="M3 4h2.2l2.1 10.2a1.6 1.6 0 0 0 1.6 1.3h7.8a1.6 1.6 0 0 0 1.6-1.2L20 8H6.3"/><circle cx="9.5" cy="19.5" r="1.4"/><circle cx="17" cy="19.5" r="1.4"/>',
		'user'     => '<circle cx="12" cy="8" r="3.6"/><path d="M4.8 20.5a7.4 7.4 0 0 1 14.4 0"/>',
		'wallet'   => '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h11.5A1.5 1.5 0 0 1 19 6.5v1"/><rect x="3.5" y="7.5" width="17" height="12" rx="2.5"/><circle cx="16" cy="13.5" r="1.3"/>',
		'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
		'heart'    => '<path d="M12 20.2 4.6 13a4.6 4.6 0 1 1 7.4-5.3A4.6 4.6 0 1 1 19.4 13Z"/>',
		'menu'     => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h11"/>',
		'close'    => '<path d="m6 6 12 12"/><path d="m18 6-12 12"/>',
		'bell'     => '<path d="M18 9a6 6 0 1 0-12 0c0 5-2 6.5-2 6.5h16S18 14 18 9"/><path d="M13.7 19a2 2 0 0 1-3.4 0"/>',
		'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.2 5.2l1.4 1.4M17.4 17.4l1.4 1.4M18.8 5.2l-1.4 1.4M6.6 17.4l-1.4 1.4"/>',
		'moon'     => '<path d="M20 13.5A8 8 0 1 1 10.5 4a6.5 6.5 0 0 0 9.5 9.5"/>',
		'chevron'  => '<path d="m9 5 7 7-7 7"/>',
		'back'     => '<path d="m14 5-7 7 7 7"/>',
		'support'  => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .9-1 1.6v.3"/><path d="M12 17.2h.01"/>',
		'tag'      => '<path d="M3.5 11.2V4.8a1.3 1.3 0 0 1 1.3-1.3h6.4a1.3 1.3 0 0 1 .9.4l8 8a1.3 1.3 0 0 1 0 1.8l-6.4 6.4a1.3 1.3 0 0 1-1.8 0l-8-8a1.3 1.3 0 0 1-.4-.9Z"/><circle cx="7.8" cy="7.8" r="1.2"/>',
		'gift'     => '<rect x="3.5" y="9" width="17" height="11.5" rx="2"/><path d="M3.5 13.5h17M12 9v11.5"/><path d="M12 9S9.5 3.5 7 4.6 9 9 12 9Zm0 0s2.5-5.5 5-4.4S15 9 12 9Z"/>',
	);

	public static function has( string $name ): bool {
		return isset( self::PATHS[ $name ] );
	}

	/**
	 * One icon, ready to print.
	 *
	 * aria-hidden on every icon without exception: an icon in V10 always sits
	 * beside a real text label or inside a control with an accessible name, so
	 * announcing it would only repeat what was already said.
	 */
	public static function svg( string $name, int $size = 24 ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return sprintf(
			'<svg viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%2$s</svg>',
			max( 12, min( 64, $size ) ),
			self::PATHS[ $name ]
		);
	}

	/** @return string[] */
	public static function names(): array {
		return array_keys( self::PATHS );
	}
}
