import type { Adjust, TextStyle } from './types';

export const ASPECTS: { id: string; label: string; w: number; h: number; hint: string }[] = [
  { id: '16:9', label: '16:9', w: 1920, h: 1080, hint: 'YouTube' },
  { id: '9:16', label: '9:16', w: 1080, h: 1920, hint: 'Shorts / Reels' },
  { id: '1:1', label: '1:1', w: 1080, h: 1080, hint: 'Square' },
  { id: '4:3', label: '4:3', w: 1440, h: 1080, hint: 'Classic' },
  { id: '3:4', label: '3:4', w: 1080, h: 1440, hint: 'Portrait' },
  { id: '4:5', label: '4:5', w: 1080, h: 1350, hint: 'Feed post' },
  { id: '21:9', label: '21:9', w: 2520, h: 1080, hint: 'Cinema' },
];

export const BLEND_MODES: { id: string; label: string }[] = [
  { id: 'source-over', label: 'Normal' },
  { id: 'multiply', label: 'Multiply' },
  { id: 'screen', label: 'Screen' },
  { id: 'overlay', label: 'Overlay' },
  { id: 'darken', label: 'Darken' },
  { id: 'lighten', label: 'Lighten' },
  { id: 'color-dodge', label: 'Color dodge' },
  { id: 'color-burn', label: 'Color burn' },
  { id: 'hard-light', label: 'Hard light' },
  { id: 'soft-light', label: 'Soft light' },
  { id: 'difference', label: 'Difference' },
  { id: 'exclusion', label: 'Exclusion' },
  { id: 'hue', label: 'Hue' },
  { id: 'saturation', label: 'Saturation' },
  { id: 'color', label: 'Color' },
  { id: 'luminosity', label: 'Luminosity' },
];

export const FONTS: { id: string; label: string; css: string }[] = [
  { id: 'Inter', label: 'Inter', css: '"Inter", system-ui, sans-serif' },
  { id: 'Poppins', label: 'Poppins', css: '"Poppins", sans-serif' },
  { id: 'Montserrat', label: 'Montserrat', css: '"Montserrat", sans-serif' },
  { id: 'Oswald', label: 'Oswald', css: '"Oswald", sans-serif' },
  { id: 'Bebas Neue', label: 'Bebas Neue', css: '"Bebas Neue", Impact, sans-serif' },
  { id: 'Anton', label: 'Anton', css: '"Anton", Impact, sans-serif' },
  { id: 'Playfair Display', label: 'Playfair Display', css: '"Playfair Display", Georgia, serif' },
  { id: 'Lobster', label: 'Lobster', css: '"Lobster", cursive' },
  { id: 'Pacifico', label: 'Pacifico', css: '"Pacifico", cursive' },
  { id: 'Permanent Marker', label: 'Permanent Marker', css: '"Permanent Marker", cursive' },
  { id: 'Roboto Mono', label: 'Roboto Mono', css: '"Roboto Mono", ui-monospace, monospace' },
  { id: 'Georgia', label: 'Georgia', css: 'Georgia, serif' },
  { id: 'Impact', label: 'Impact', css: 'Impact, "Arial Black", sans-serif' },
  { id: 'Courier New', label: 'Courier New', css: '"Courier New", monospace' },
];

export const fontCss = (id: string): string => FONTS.find((f) => f.id === id)?.css ?? `"${id}", sans-serif`;

export const DEFAULT_TEXT: TextStyle = {
  content: 'Your text',
  font: 'Poppins',
  size: 96,
  color: '#ffffff',
  bold: true,
  italic: false,
  underline: false,
  align: 'center',
  letterSpacing: 0,
  lineHeight: 1.2,
  strokeColor: '#000000',
  strokeWidth: 0,
  shadow: false,
  shadowColor: '#000000',
  shadowBlur: 12,
  shadowX: 0,
  shadowY: 4,
  bg: false,
  bgColor: '#000000',
  bgOpacity: 0.7,
  bgRadius: 16,
};

export const TEXT_PRESETS: { id: string; label: string; style: Partial<TextStyle> }[] = [
  { id: 'default', label: 'Default', style: {} },
  { id: 'outline', label: 'Bold outline', style: { font: 'Bebas Neue', size: 140, bold: false, strokeWidth: 10, strokeColor: '#000000' } },
  { id: 'subtitle', label: 'Yellow sub', style: { font: 'Montserrat', size: 72, color: '#ffe600', strokeWidth: 8, strokeColor: '#000000' } },
  { id: 'boxed', label: 'Boxed', style: { font: 'Inter', size: 72, bg: true, bgColor: '#000000', bgOpacity: 0.75 } },
  { id: 'neon', label: 'Neon', style: { font: 'Montserrat', color: '#7df9ff', shadow: true, shadowColor: '#00e5ff', shadowBlur: 40, shadowY: 0 } },
  { id: 'pinkpop', label: 'Pink pop', style: { font: 'Poppins', color: '#ff4fa3', strokeWidth: 10, strokeColor: '#ffffff' } },
  { id: 'retro', label: 'Retro', style: { font: 'Anton', color: '#ffd166', shadow: true, shadowColor: '#ef476f', shadowBlur: 0, shadowX: 8, shadowY: 8 } },
  { id: 'elegant', label: 'Elegant', style: { font: 'Playfair Display', bold: false, italic: true, size: 110, shadow: true, shadowBlur: 20, shadowY: 6, shadowColor: '#000000' } },
  { id: 'script', label: 'Script', style: { font: 'Pacifico', bold: false, size: 110, shadow: true, shadowBlur: 10 } },
  { id: 'marker', label: 'Marker', style: { font: 'Permanent Marker', bold: false, color: '#111111', bg: true, bgColor: '#ffffff', bgOpacity: 1, bgRadius: 6 } },
  { id: 'typewriter', label: 'Typewriter', style: { font: 'Courier New', size: 64, color: '#1d1d1d', bg: true, bgColor: '#f3ecd9', bgOpacity: 1, bgRadius: 2 } },
  { id: 'lobster', label: 'Groovy', style: { font: 'Lobster', bold: false, size: 120, color: '#ff8a3d', strokeWidth: 6, strokeColor: '#3b1d00' } },
];

/** Filter presets: deltas applied on top of the clip's own adjustments, scaled by intensity. */
export interface FilterDef {
  id: string;
  label: string;
  adjust: Partial<Adjust>;
  sepia?: number;
  grayscale?: number;
  invert?: number;
}

export const FILTERS: FilterDef[] = [
  { id: 'none', label: 'None', adjust: {} },
  { id: 'vivid', label: 'Vivid', adjust: { saturation: 0.45, contrast: 0.15 } },
  { id: 'warm', label: 'Warm', adjust: { temperature: 0.55, saturation: 0.1 } },
  { id: 'cool', label: 'Cool', adjust: { temperature: -0.55, contrast: 0.05 } },
  { id: 'golden', label: 'Golden', adjust: { temperature: 0.35, saturation: 0.3, brightness: 0.03 }, sepia: 0.2 },
  { id: 'mono', label: 'Mono', adjust: { contrast: 0.1 }, grayscale: 1 },
  { id: 'noir', label: 'Noir', adjust: { contrast: 0.55, brightness: -0.08, vignette: 0.5 }, grayscale: 1 },
  { id: 'vintage', label: 'Vintage', adjust: { contrast: -0.1, saturation: -0.2, vignette: 0.35 }, sepia: 0.55 },
  { id: 'fade', label: 'Fade', adjust: { contrast: -0.28, brightness: 0.08, saturation: -0.2 } },
  { id: 'retro', label: 'Retro', adjust: { hue: -12, saturation: 0.2, contrast: 0.12 }, sepia: 0.3 },
  { id: 'dramatic', label: 'Dramatic', adjust: { contrast: 0.42, saturation: -0.15, brightness: -0.05, vignette: 0.3 } },
  { id: 'pastel', label: 'Pastel', adjust: { saturation: -0.3, brightness: 0.12, contrast: -0.15 } },
  { id: 'matte', label: 'Matte', adjust: { contrast: -0.18, brightness: 0.04, saturation: -0.05 } },
  { id: 'teal', label: 'Teal & Orange', adjust: { temperature: 0.2, tint: -0.15, contrast: 0.2, saturation: 0.15, hue: -8 } },
  { id: 'cyber', label: 'Cyber', adjust: { hue: 40, saturation: 0.4, contrast: 0.2, tint: 0.3 } },
  { id: 'lomo', label: 'Lomo', adjust: { contrast: 0.3, saturation: 0.35, vignette: 0.7 } },
  { id: 'sunset', label: 'Sunset', adjust: { temperature: 0.7, tint: 0.2, saturation: 0.2 } },
  { id: 'moonlight', label: 'Moonlight', adjust: { temperature: -0.7, brightness: -0.1, saturation: -0.3 } },
  { id: 'invert', label: 'Negative', adjust: {}, invert: 1 },
];

export const ZERO_ADJUST: Adjust = {
  brightness: 0,
  contrast: 0,
  saturation: 0,
  hue: 0,
  temperature: 0,
  tint: 0,
  vignette: 0,
  blur: 0,
};

export const ANIMATIONS_IN: { id: string; label: string }[] = [
  { id: 'none', label: 'None' },
  { id: 'fade', label: 'Fade in' },
  { id: 'zoomIn', label: 'Zoom in' },
  { id: 'zoomOut', label: 'Zoom out' },
  { id: 'slideLeft', label: 'Slide left' },
  { id: 'slideRight', label: 'Slide right' },
  { id: 'slideUp', label: 'Slide up' },
  { id: 'slideDown', label: 'Slide down' },
  { id: 'pop', label: 'Pop' },
  { id: 'drop', label: 'Drop' },
  { id: 'spin', label: 'Spin' },
  { id: 'blur', label: 'Blur in' },
  { id: 'wobble', label: 'Wobble' },
  { id: 'typewriter', label: 'Typewriter' },
];

export const ANIMATIONS_OUT: { id: string; label: string }[] = [
  { id: 'none', label: 'None' },
  { id: 'fade', label: 'Fade out' },
  { id: 'zoomIn', label: 'Zoom in' },
  { id: 'zoomOut', label: 'Zoom out' },
  { id: 'slideLeft', label: 'Slide left' },
  { id: 'slideRight', label: 'Slide right' },
  { id: 'slideUp', label: 'Slide up' },
  { id: 'slideDown', label: 'Slide down' },
  { id: 'pop', label: 'Shrink' },
  { id: 'spin', label: 'Spin' },
  { id: 'blur', label: 'Blur out' },
  { id: 'typewriter', label: 'Untype' },
];

export const TRANSITIONS: { id: string; label: string }[] = [
  { id: 'fade', label: 'Dissolve' },
  { id: 'black', label: 'Dip to black' },
  { id: 'white', label: 'Flash' },
  { id: 'wipeLeft', label: 'Wipe left' },
  { id: 'wipeRight', label: 'Wipe right' },
  { id: 'wipeUp', label: 'Wipe up' },
  { id: 'wipeDown', label: 'Wipe down' },
  { id: 'slideLeft', label: 'Slide left' },
  { id: 'slideRight', label: 'Slide right' },
  { id: 'pushUp', label: 'Push up' },
  { id: 'zoomIn', label: 'Zoom in' },
  { id: 'zoomOut', label: 'Zoom out' },
  { id: 'circle', label: 'Iris' },
  { id: 'clock', label: 'Clock wipe' },
  { id: 'doors', label: 'Doors' },
  { id: 'blur', label: 'Blur' },
  { id: 'spin', label: 'Spin' },
];

export const EFFECTS: { id: string; label: string; hue: number }[] = [
  { id: 'shake', label: 'Shake', hue: 10 },
  { id: 'zoomPulse', label: 'Zoom pulse', hue: 30 },
  { id: 'blur', label: 'Dream blur', hue: 200 },
  { id: 'mirror', label: 'Mirror', hue: 180 },
  { id: 'rgbSplit', label: 'RGB split', hue: 300 },
  { id: 'glitch', label: 'Glitch', hue: 280 },
  { id: 'vhs', label: 'VHS', hue: 250 },
  { id: 'vignette', label: 'Vignette', hue: 0 },
  { id: 'grain', label: 'Film grain', hue: 40 },
  { id: 'oldFilm', label: 'Old film', hue: 35 },
  { id: 'flash', label: 'Strobe', hue: 55 },
  { id: 'letterbox', label: 'Cinema bars', hue: 220 },
  { id: 'lightLeak', label: 'Light leak', hue: 20 },
  { id: 'snow', label: 'Snowfall', hue: 195 },
  { id: 'mono', label: 'Black & white', hue: 0 },
  { id: 'pixelate', label: 'Pixelate', hue: 140 },
];

export const STICKERS: { cat: string; items: string[] }[] = [
  { cat: 'Smileys', items: ['😀', '😂', '🤣', '😍', '🥰', '😎', '🤩', '😭', '😱', '🤯', '😴', '🤔', '🙄', '😏', '🥳', '😇', '🤪', '😡'] },
  { cat: 'Love', items: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '💖', '💘', '💕', '💯', '💋'] },
  { cat: 'Celebrate', items: ['🎉', '🎊', '🎈', '🎁', '🏆', '🥇', '✨', '⭐', '🌟', '🔥', '💥', '🎂'] },
  { cat: 'Gestures', items: ['👍', '👎', '👏', '🙌', '🙏', '👀', '💪', '✌️', '🤞', '👉', '👈', '👆'] },
  { cat: 'Symbols', items: ['✅', '❌', '⚠️', '❓', '❗', '➡️', '⬅️', '⬆️', '⬇️', '🔴', '🟢', '💬', '🔊', '📍', '⏰', '💡'] },
  { cat: 'Nature', items: ['☀️', '🌙', '⛅', '🌈', '❄️', '⚡', '🌊', '🌸', '🌻', '🍀', '🌴', '🍁'] },
  { cat: 'Animals', items: ['🐶', '🐱', '🦊', '🐻', '🐼', '🐸', '🦄', '🐧', '🦋', '🐝', '🐙', '🦖'] },
  { cat: 'Food', items: ['🍕', '🍔', '🍟', '🌮', '🍩', '🍦', '🍓', '🥑', '☕', '🧋', '🍿', '🍫'] },
];

export const SOUNDS: { id: string; label: string; group: 'sfx' | 'music'; dur: string }[] = [
  { id: 'beat', label: 'Upbeat groove', group: 'music', dur: '0:16' },
  { id: 'chill', label: 'Chill pad', group: 'music', dur: '0:16' },
  { id: 'pulse', label: 'Synth pulse', group: 'music', dur: '0:16' },
  { id: 'whoosh', label: 'Whoosh', group: 'sfx', dur: '1.3s' },
  { id: 'pop', label: 'Pop', group: 'sfx', dur: '0.3s' },
  { id: 'ding', label: 'Ding', group: 'sfx', dur: '2.2s' },
  { id: 'click', label: 'Click', group: 'sfx', dur: '0.1s' },
  { id: 'beep', label: 'Censor beep', group: 'sfx', dur: '1s' },
  { id: 'riser', label: 'Riser', group: 'sfx', dur: '3s' },
  { id: 'boom', label: 'Boom', group: 'sfx', dur: '2s' },
  { id: 'notify', label: 'Notification', group: 'sfx', dur: '0.9s' },
  { id: 'swipe', label: 'Swipe', group: 'sfx', dur: '0.4s' },
];

export const TRACK_LABEL: Record<string, string> = {
  video: 'Video',
  text: 'Text',
  audio: 'Audio',
  effect: 'Effects',
};

export const ANIM_GLYPH: Record<string, string> = {
  none: '∅', fade: '◐', zoomIn: '⤢', zoomOut: '⤡', slideLeft: '←', slideRight: '→', slideUp: '↑', slideDown: '↓',
  pop: '✺', drop: '⤓', spin: '↻', blur: '◌', wobble: '〰', typewriter: '⌨',
};

export const TRANS_GLYPH: Record<string, string> = {
  none: '∅', fade: '◐', black: '●', white: '○', wipeLeft: '⇠', wipeRight: '⇢', wipeUp: '⇡', wipeDown: '⇣',
  slideLeft: '←', slideRight: '→', pushUp: '↑', zoomIn: '⤢', zoomOut: '⤡', circle: '◎', clock: '◔', doors: '⇹', blur: '◌', spin: '↻',
};

/** CSS filter approximation of a clip filter, for thumbnails. */
export function cssFilter(adj: Partial<Adjust>, f?: (typeof FILTERS)[number], intensity = 1): string {
  const a = { ...ZERO_ADJUST, ...adj };
  const fa = f?.adjust ?? {};
  const k = intensity;
  const b = a.brightness + (fa.brightness ?? 0) * k;
  const c = a.contrast + (fa.contrast ?? 0) * k;
  const s = a.saturation + (fa.saturation ?? 0) * k;
  const hue = a.hue + (fa.hue ?? 0) * k;
  const temp = a.temperature + (fa.temperature ?? 0) * k;
  const parts = [`brightness(${1 + b})`, `contrast(${1 + c})`, `saturate(${Math.max(0, 1 + s)})`, `hue-rotate(${hue - Math.min(0, temp) * 20}deg)`];
  const sep = (f?.sepia ?? 0) * k + Math.max(0, temp) * 0.35;
  if (sep) parts.push(`sepia(${sep})`);
  if (f?.grayscale) parts.push(`grayscale(${f.grayscale * k})`);
  if (f?.invert) parts.push(`invert(${f.invert * k})`);
  return parts.join(' ');
}


/** Inline CSS that previews a text preset in the UI. */
export function textPreviewStyle(style: Partial<TextStyle>): string {
  const s = { ...DEFAULT_TEXT, ...style };
  return [
    `font-family:${fontCss(s.font).replace(/"/g, "'")}`,
    `color:${s.color}`,
    `font-weight:${s.bold ? 700 : 400}`,
    s.italic ? 'font-style:italic' : '',
    s.strokeWidth ? `-webkit-text-stroke:${Math.min(3, s.strokeWidth / 3)}px ${s.strokeColor};paint-order:stroke fill` : '',
    s.shadow ? `text-shadow:${s.shadowX / 3}px ${s.shadowY / 3}px ${s.shadowBlur / 3}px ${s.shadowColor}` : '',
    s.bg ? `background:${s.bgColor};padding:0 6px;border-radius:4px` : '',
  ]
    .filter(Boolean)
    .join(';');
}
