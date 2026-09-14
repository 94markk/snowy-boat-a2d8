<?php
/**
 * One icon set, drawn at 24×24 with a single stroke weight and currentColor,
 * so every icon works on every badge tone and in both themes.
 *
 * No emoji anywhere in the storefront: an emoji is a font glyph that renders
 * differently on every phone and ignores the colour around it.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array<string,string> name => inner SVG markup
 */
function ds_icons(): array {
	return array(
		/* Topics */
		'gamepad'   => '<path d="M7.5 11h-3M6 9.5v3"/><circle cx="16" cy="10.5" r=".9"/><circle cx="18.4" cy="13" r=".9"/><path d="M8.2 7h7.6a4.2 4.2 0 0 1 4.1 3.3l1 4.6A2.6 2.6 0 0 1 18.4 18c-1 0-1.6-.5-2.2-1.1l-.9-.9H8.7l-.9.9c-.6.6-1.2 1.1-2.2 1.1a2.6 2.6 0 0 1-2.5-3.1l1-4.6A4.2 4.2 0 0 1 8.2 7Z"/>',
		'wallet'    => '<path d="M3.5 8A2.5 2.5 0 0 1 6 5.5h11A1.5 1.5 0 0 1 18.5 7v1"/><rect x="3.5" y="8" width="17" height="11" rx="2.5"/><circle cx="16.2" cy="13.5" r="1.15"/>',
		'gift'      => '<rect x="3.5" y="9.5" width="17" height="10.5" rx="2"/><path d="M3.5 13.8h17M12 9.5V20"/><path d="M12 9.5S9.7 4.4 7.4 5.4 9.2 9.5 12 9.5Zm0 0s2.3-5.1 4.6-4.1S14.8 9.5 12 9.5Z"/>',
		'share'     => '<circle cx="17.5" cy="6.5" r="2.4"/><circle cx="6.5" cy="12" r="2.4"/><circle cx="17.5" cy="17.5" r="2.4"/><path d="m8.7 10.9 6.6-3.3M8.7 13.1l6.6 3.3"/>',
		'play'      => '<rect x="2.8" y="5" width="18.4" height="13" rx="2.6"/><path d="m10.4 9.6 4.4 2.4-4.4 2.4Z"/>',
		'phone'     => '<rect x="6.5" y="2.8" width="11" height="18.4" rx="2.6"/><path d="M10.6 5.4h2.8"/><path d="M11 18.6h2"/>',
		/* Status */
		'crown'     => '<path d="m3.6 7.8 3.2 2.6L12 5l5.2 5.4 3.2-2.6-1.6 9.4H5.2Z"/><path d="M5.6 19.6h12.8"/>',
		'tag'       => '<path d="M3.6 11.3V5.1a1.3 1.3 0 0 1 1.3-1.3h6.2a1.3 1.3 0 0 1 .9.4l8 8a1.3 1.3 0 0 1 0 1.8l-6.2 6.2a1.3 1.3 0 0 1-1.8 0l-8-8a1.3 1.3 0 0 1-.4-.9Z"/><circle cx="7.9" cy="7.9" r="1.15"/>',
		'flame'     => '<path d="M12 3s.9 2.8-1.1 4.8c-1.7 1.7-3.4 3-3.4 5.6a4.5 4.5 0 0 0 9 0c0-1.6-.7-2.8-1.6-3.8-.3 1-1 1.6-1.7 1.6.8-2.6-1.2-6.2-1.2-8.2Z"/>',
		'bolt'      => '<path d="M13.2 2.5 5.4 13.1h5.6L10.8 21.5l7.8-10.6H13Z"/>',
		'star'      => '<path d="m12 3.6 2.6 5.4 5.9.8-4.3 4.1 1.1 5.9L12 17l-5.3 2.8 1.1-5.9-4.3-4.1 5.9-.8Z"/>',
		'heart'     => '<path d="M12 20.3 4.7 13.1a4.6 4.6 0 1 1 7.3-5.4 4.6 4.6 0 1 1 7.3 5.4Z"/>',
		/* Chrome */
		'home'      => '<path d="m3.5 11 8.5-7 8.5 7"/><path d="M5.5 9.5V20h13V9.5"/><path d="M10 20v-5.5h4V20"/>',
		'grid'      => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.8"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.8"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.8"/>',
		'search'    => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
		'cart'      => '<path d="M3 4h2.2l2.3 11.2a1.5 1.5 0 0 0 1.5 1.2h8.6a1.5 1.5 0 0 0 1.5-1.2L20.5 8H6.2"/><circle cx="9.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/>',
		'user'      => '<circle cx="12" cy="8.5" r="3.8"/><path d="M4.8 20a7.2 7.2 0 0 1 14.4 0"/>',
		'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
		'close'     => '<path d="m6 6 12 12M18 6 6 18"/>',
		'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"/>',
		'moon'      => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
		'arrow'     => '<path d="M5 12h14M14 7l5 5-5 5"/>',
		'chevron'   => '<path d="m9 6 6 6-6 6"/>',
		'check'     => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
		'shield'    => '<path d="M12 3 5 6v5.2c0 4.5 2.8 8.2 7 9.8 4.2-1.6 7-5.3 7-9.8V6l-7-3Z"/><path d="m8.8 12 2.1 2.1 4.5-4.7"/>',
		'chat'      => '<path d="M20 11.6a7.5 7.5 0 0 1-8 7.4 8.7 8.7 0 0 1-3.1-.7L4 20l1.7-4.3A7.4 7.4 0 1 1 20 11.6Z"/><path d="M8.5 11.8h.01M12 11.8h.01M15.5 11.8h.01"/>',
		'support'   => '<path d="M4.5 13v-2a7.5 7.5 0 0 1 15 0v2"/><rect x="3" y="12" width="4" height="6" rx="1.5"/><rect x="17" y="12" width="4" height="6" rx="1.5"/><path d="M19 18a3 3 0 0 1-3 3h-3"/>',
		'globe'     => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.6 2.6 14.4 0 17M12 3.5c-2.6 2.6-2.6 14.4 0 17"/>',
		'clock'     => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
		'lock'      => '<rect x="5" y="10.5" width="14" height="10" rx="2.2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
		'trophy'    => '<path d="M7 4h10v5a5 5 0 0 1-10 0V4Z"/><path d="M7 6H4.5a2.5 2.5 0 0 0 2.5 4M17 6h2.5a2.5 2.5 0 0 1-2.5 4M12 14v3M8.5 20h7M9.5 17h5"/>',
		'info'      => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/>',
		'plus'      => '<path d="M12 5v14M5 12h14"/>',
		'minus'     => '<path d="M5 12h14"/>',
		'trash'     => '<path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l.8 12.5h9.4L17.5 7"/>',
		'external'  => '<path d="M14 4h6v6M20 4l-9 9M18 13v6H5V6h6"/>',
		'image'     => '<rect x="3.5" y="5" width="17" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="m5 17 4.5-4.5 3 3 2.5-2.5 4 4"/>',
		/* Social — filled logos */
		'whatsapp'  => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.7-1.2A9 9 0 1 0 12 3z"/><path d="M9.2 8.8c.2-.4.5-.5.8-.5h.5c.2 0 .4.1.5.4l.6 1.5c.1.2 0 .4-.1.6l-.5.6c.6 1 1.5 1.8 2.5 2.4l.6-.5c.2-.2.4-.2.6-.1l1.5.7c.2.1.4.3.3.6-.1.9-.7 1.5-1.6 1.6-2.9.1-6.2-3.2-6.1-6.1 0-.4.2-.8.4-1.2z"/>',
		'telegram'  => '<path d="m3.5 11.2 16-6.7c.6-.2 1.1.2 1 .9L18 19.8c-.1.6-.6.8-1.1.5l-4.3-3.2-2.1 2c-.2.2-.6.1-.6-.2l.3-3.4 7-6.3-8.6 5.3-3.7-1.2c-.7-.2-.7-.9 0-1.1z"/>',
		'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r=".8"/>',
		'facebook'  => '<path d="M14 8.5V6.8c0-.8.5-1.3 1.3-1.3H17V2.5h-2.4C11.9 2.5 11 4.2 11 6.5v2H8.5V12H11v9.5h3V12h2.6l.4-3.5z"/>',
		'tiktok'    => '<path d="M14 3c.3 2.3 1.8 3.8 4 4v3c-1.5 0-2.9-.5-4-1.3V15a5 5 0 1 1-5-5v3a2 2 0 1 0 2 2V3z"/>',
		'youtube'   => '<path d="M21 8.2a2.5 2.5 0 0 0-1.8-1.8C17.6 6 12 6 12 6s-5.6 0-7.2.4A2.5 2.5 0 0 0 3 8.2 26 26 0 0 0 2.6 12 26 26 0 0 0 3 15.8a2.5 2.5 0 0 0 1.8 1.8c1.6.4 7.2.4 7.2.4s5.6 0 7.2-.4a2.5 2.5 0 0 0 1.8-1.8A26 26 0 0 0 21.4 12 26 26 0 0 0 21 8.2z"/><path d="m10 9.5 4.5 2.5-4.5 2.5z"/>',
	);
}

/**
 * Render an icon.
 *
 * @param string $name  Key from ds_icons().
 * @param string $class Extra classes.
 * @param string $label Accessible label; empty = decorative.
 */
function ds_icon( string $name, string $class = '', string $label = '' ): string {
	$icons = ds_icons();
	if ( ! isset( $icons[ $name ] ) ) {
		return '';
	}
	$attrs = ' class="ds-icon' . ( '' !== $class ? ' ' . esc_attr( $class ) : '' ) . '"';
	if ( '' === $label ) {
		$attrs .= ' aria-hidden="true" focusable="false"';
	} else {
		$attrs .= ' role="img" aria-label="' . esc_attr( $label ) . '"';
	}
	return '<svg' . $attrs . ' viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $icons[ $name ] . '</svg>';
}

/**
 * Echo helper.
 */
function ds_the_icon( string $name, string $class = '', string $label = '' ): void {
	echo ds_icon( $name, $class, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG built from theme constants.
}
