// Frame compositor: draws the project at time `t` into a canvas.
// Used for the live preview, PNG snapshots and video export.
import type { Chroma, Clip, Track } from './types';
import { EFFECTS, FILTERS, fontCss } from './presets';
import { clipEnd, getTransformAt, mainTrack } from './model';
import { store } from './store';
import { clamp, easing, hexToRgb, lerp, rand, rgba } from './utils';

export interface SourceProvider {
  frame(clip: Clip): CanvasImageSource | null;
  dims(clip: Clip): { w: number; h: number } | null;
}

interface Mods {
  alpha?: number;
  dx?: number;
  dy?: number;
  scale?: number;
  rot?: number;
  blur?: number;
  clip?: (ctx: CanvasRenderingContext2D) => void;
}

interface FrameState {
  x: number;
  y: number;
  scale: number;
  rot: number;
  alpha: number;
  blur: number;
  reveal: number;
}

export interface Geometry {
  cx: number;
  cy: number;
  w: number;
  h: number;
  rot: number;
}

interface TextMetrics2 {
  w: number;
  h: number;
  lines: string[];
  lineH: number;
  pad: number;
}

const STICKER_SIZE = 220;
const EMOJI_FONT = '"Apple Color Emoji","Segoe UI Emoji","Noto Color Emoji",sans-serif';
const BIG = 1e5;

function mk(willRead = false): [HTMLCanvasElement, CanvasRenderingContext2D] {
  const c = document.createElement('canvas');
  c.width = c.height = 2;
  return [c, c.getContext('2d', willRead ? { willReadFrequently: true } : undefined)!];
}

export function textFont(c: Clip): string {
  const s = c.text!;
  return `${s.italic ? 'italic ' : ''}${s.bold ? 700 : 400} ${s.size}px ${fontCss(s.font)}`;
}

export class Renderer {
  private ctx!: CanvasRenderingContext2D;
  private canvas!: HTMLCanvasElement;
  private k = 1;
  private W = 1920;
  private H = 1080;
  private layer = mk();
  private maskC = mk();
  private scratch = mk();
  private chan = [mk(), mk(), mk()];
  private chroma = mk(true);
  private tiny = mk();
  private measure = mk()[1];
  private grain: HTMLCanvasElement | null = null;
  private textCache = new Map<string, TextMetrics2>();

  constructor(private src: SourceProvider) {}

  render(canvas: HTMLCanvasElement, t: number): void {
    const p = store.project;
    const ctx = canvas.getContext('2d')!;
    this.ctx = ctx;
    this.canvas = canvas;
    this.W = p.width;
    this.H = p.height;
    this.k = canvas.width / p.width;
    for (const [c] of [this.layer, this.maskC, this.scratch, ...this.chan]) {
      if (c.width !== canvas.width || c.height !== canvas.height) {
        c.width = canvas.width;
        c.height = canvas.height;
      }
    }
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalAlpha = 1;
    ctx.globalCompositeOperation = 'source-over';
    ctx.filter = 'none';
    ctx.imageSmoothingQuality = 'high';
    ctx.fillStyle = p.background.type === 'color' ? p.background.color : '#000';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.setTransform(this.k, 0, 0, this.k, 0, 0);

    if (p.background.type === 'blur') {
      const m = mainTrack(p);
      const c = store.clipsOn(m.id).find((c) => t >= c.start && t < clipEnd(c));
      if (c && !m.hidden && (c.type === 'video' || c.type === 'image')) this.drawBlurBg(c, p.background.blur);
    }

    const visual = p.tracks.filter((tr) => (tr.kind === 'video' || tr.kind === 'text') && !tr.hidden).reverse();
    for (const tr of visual) this.drawTrack(tr, t);

    const fx = p.tracks.filter((tr) => tr.kind === 'effect' && !tr.hidden).reverse();
    for (const tr of fx) {
      for (const c of store.clipsOn(tr.id)) {
        if (t >= c.start && t < clipEnd(c)) this.applyEffect(c, t);
      }
    }
    ctx.setTransform(1, 0, 0, 1, 0, 0);
  }

  // ---------------------------------------------------------------- tracks

  private drawTrack(tr: Track, t: number): void {
    const list = store.clipsOn(tr.id);
    for (let i = 1; i < list.length; i++) {
      const a = list[i - 1];
      const b = list[i];
      if (!b.transition || Math.abs(clipEnd(a) - b.start) > 1e-3) continue;
      const d = b.transition.duration;
      const s = b.start - d / 2;
      if (t >= s && t < s + d) {
        this.drawTransition(tr, a, b, (t - s) / d, t);
        return;
      }
    }
    const c = list.find((c) => t >= c.start && t < clipEnd(c));
    if (c) this.drawClip(c, t, {});
  }

  private drawClip(c: Clip, t: number, mods: Mods): void {
    if (c.type === 'video' || c.type === 'image') this.drawMedia(c, t, mods);
    else if (c.type === 'text') this.drawText(c, t, mods);
    else if (c.type === 'sticker') this.drawSticker(c, t, mods);
  }

  private state(c: Clip, t: number, mods: Mods): FrameState {
    const local = clamp(t - c.start, 0, c.duration);
    const tr = getTransformAt(c, local);
    const s: FrameState = { x: tr.x, y: tr.y, scale: tr.scale, rot: tr.rotation, alpha: tr.opacity, blur: 0, reveal: 1 };
    if (c.animIn.type !== 'none' && local < c.animIn.duration) this.anim(s, c, c.animIn.type, local / c.animIn.duration, 'in');
    const rem = c.duration - local;
    if (c.animOut.type !== 'none' && rem < c.animOut.duration) this.anim(s, c, c.animOut.type, rem / c.animOut.duration, 'out');
    s.x += mods.dx ?? 0;
    s.y += mods.dy ?? 0;
    s.scale *= mods.scale ?? 1;
    s.rot += mods.rot ?? 0;
    s.alpha *= mods.alpha ?? 1;
    s.blur += mods.blur ?? 0;
    s.scale = Math.max(0.001, s.scale);
    return s;
  }

  private anim(s: FrameState, c: Clip, type: string, q: number, dir: 'in' | 'out'): void {
    q = clamp(q, 0, 1);
    const e = easing.outCubic(q);
    const inv = 1 - e;
    const sign = dir === 'in' ? 1 : -1;
    const { W, H } = this;
    switch (type) {
      case 'fade':
        s.alpha *= e;
        break;
      case 'zoomIn':
        s.scale *= dir === 'in' ? lerp(0.4, 1, e) : lerp(1.6, 1, e);
        s.alpha *= e;
        break;
      case 'zoomOut':
        s.scale *= dir === 'in' ? lerp(1.6, 1, e) : lerp(0.4, 1, e);
        s.alpha *= e;
        break;
      case 'slideLeft':
        s.x += sign * W * 0.5 * inv;
        s.alpha *= Math.min(1, e * 1.5);
        break;
      case 'slideRight':
        s.x -= sign * W * 0.5 * inv;
        s.alpha *= Math.min(1, e * 1.5);
        break;
      case 'slideUp':
        s.y += sign * H * 0.4 * inv;
        s.alpha *= Math.min(1, e * 1.5);
        break;
      case 'slideDown':
        s.y -= sign * H * 0.4 * inv;
        s.alpha *= Math.min(1, e * 1.5);
        break;
      case 'pop':
        s.scale *= dir === 'in' ? Math.max(0.01, easing.outBack(q)) : Math.max(0.01, e);
        s.alpha *= Math.min(1, q * 3);
        break;
      case 'drop':
        s.y -= H * 0.6 * (1 - easing.outBounce(q));
        s.alpha *= Math.min(1, q * 4);
        break;
      case 'spin':
        s.rot += -sign * 360 * inv;
        s.scale *= lerp(0.3, 1, e);
        s.alpha *= e;
        break;
      case 'blur':
        s.blur += 30 * inv;
        s.alpha *= e;
        break;
      case 'wobble':
        s.rot += Math.sin(q * Math.PI * 4) * 14 * (1 - q);
        s.scale *= lerp(0.8, 1, e);
        s.alpha *= Math.min(1, q * 3);
        break;
      case 'typewriter':
        if (c.type === 'text') s.reveal = Math.min(s.reveal, q);
        else s.alpha *= e;
        break;
    }
  }

  // ---------------------------------------------------------------- media

  private filterFor(c: Clip, extraBlur: number): string {
    const f = FILTERS.find((x) => x.id === c.filter.id);
    const i = f && f.id !== 'none' ? c.filter.intensity : 0;
    const a = c.adjust;
    const fa = f?.adjust ?? {};
    const b = a.brightness + (fa.brightness ?? 0) * i;
    const con = a.contrast + (fa.contrast ?? 0) * i;
    const sat = a.saturation + (fa.saturation ?? 0) * i;
    const hue = a.hue + (fa.hue ?? 0) * i;
    const parts: string[] = [];
    if (Math.abs(b) > 0.001) parts.push(`brightness(${Math.max(0, 1 + b)})`);
    if (Math.abs(con) > 0.001) parts.push(`contrast(${Math.max(0, 1 + con)})`);
    if (Math.abs(sat) > 0.001) parts.push(`saturate(${Math.max(0, 1 + sat)})`);
    if (Math.abs(hue) > 0.1) parts.push(`hue-rotate(${hue}deg)`);
    if (f?.sepia) parts.push(`sepia(${f.sepia * i})`);
    if (f?.grayscale) parts.push(`grayscale(${f.grayscale * i})`);
    if (f?.invert) parts.push(`invert(${f.invert * i})`);
    const blur = a.blur + extraBlur;
    if (blur > 0.05) parts.push(`blur(${(blur * this.k).toFixed(2)}px)`);
    return parts.length ? parts.join(' ') : 'none';
  }

  private washes(c: Clip): { temp: number; tint: number; vig: number } {
    const f = FILTERS.find((x) => x.id === c.filter.id);
    const i = f && f.id !== 'none' ? c.filter.intensity : 0;
    return {
      temp: clamp(c.adjust.temperature + (f?.adjust.temperature ?? 0) * i, -1, 1),
      tint: clamp(c.adjust.tint + (f?.adjust.tint ?? 0) * i, -1, 1),
      vig: clamp(c.adjust.vignette + (f?.adjust.vignette ?? 0) * i, 0, 1),
    };
  }

  private drawBlurBg(c: Clip, blur: number): void {
    const src = this.src.frame(c);
    const d = this.src.dims(c);
    if (!src || !d) return;
    const s = Math.max(this.W / d.w, this.H / d.h) * 1.15;
    const ctx = this.ctx;
    ctx.save();
    ctx.filter = `blur(${blur * this.k}px) brightness(0.85)`;
    ctx.drawImage(src, (this.W - d.w * s) / 2, (this.H - d.h * s) / 2, d.w * s, d.h * s);
    ctx.restore();
  }

  private drawMedia(c: Clip, t: number, mods: Mods): void {
    const src = this.src.frame(c);
    const d = this.src.dims(c);
    if (!src || !d) return;
    const { W, H, k } = this;
    const cr = c.crop;
    const sx = d.w * cr.l;
    const sy = d.h * cr.t;
    const sw = d.w * (1 - cr.l - cr.r);
    const sh = d.h * (1 - cr.t - cr.b);
    if (sw < 1 || sh < 1) return;
    const st = this.state(c, t, mods);
    if (st.alpha <= 0.001) return;
    const fit = Math.min(W / sw, H / sh);
    const dw = sw * fit * st.scale;
    const dh = sh * fit * st.scale;

    let image: CanvasImageSource = src;
    let [isx, isy, isw, ish] = [sx, sy, sw, sh];
    if (c.chroma.enabled) {
      const keyed = this.chromaKey(src, sx, sy, sw, sh, c.chroma, dw * k);
      image = keyed;
      [isx, isy, isw, ish] = [0, 0, keyed.width, keyed.height];
    }

    const w = this.washes(c);
    const useLayer = c.mask.type !== 'none' || Math.abs(w.temp) > 0.01 || Math.abs(w.tint) > 0.01 || w.vig > 0.01;
    let target = this.ctx;
    if (useLayer) {
      const [lc, lctx] = this.layer;
      lctx.setTransform(1, 0, 0, 1, 0, 0);
      lctx.globalCompositeOperation = 'source-over';
      lctx.globalAlpha = 1;
      lctx.clearRect(0, 0, lc.width, lc.height);
      lctx.setTransform(k, 0, 0, k, 0, 0);
      target = lctx;
    }
    target.save();
    if (mods.clip) {
      target.beginPath();
      mods.clip(target);
      target.clip();
    }
    if (!useLayer) {
      target.globalAlpha = st.alpha;
      target.globalCompositeOperation = c.blend as GlobalCompositeOperation;
    }
    target.filter = this.filterFor(c, st.blur);
    target.translate(W / 2 + st.x, H / 2 + st.y);
    target.rotate((st.rot * Math.PI) / 180);
    target.scale(c.flipH ? -1 : 1, c.flipV ? -1 : 1);
    target.drawImage(image, isx, isy, isw, ish, -dw / 2, -dh / 2, dw, dh);
    target.filter = 'none';
    if (useLayer) {
      target.globalCompositeOperation = 'source-atop';
      if (Math.abs(w.temp) > 0.01) {
        target.fillStyle = w.temp > 0 ? `rgba(255,140,30,${0.32 * w.temp})` : `rgba(40,130,255,${0.32 * -w.temp})`;
        target.fillRect(-dw / 2, -dh / 2, dw, dh);
      }
      if (Math.abs(w.tint) > 0.01) {
        target.fillStyle = w.tint > 0 ? `rgba(255,0,190,${0.22 * w.tint})` : `rgba(0,230,90,${0.22 * -w.tint})`;
        target.fillRect(-dw / 2, -dh / 2, dw, dh);
      }
      if (w.vig > 0.01) {
        const g = target.createRadialGradient(0, 0, Math.min(dw, dh) * 0.25, 0, 0, Math.hypot(dw, dh) / 2);
        g.addColorStop(0, 'rgba(0,0,0,0)');
        g.addColorStop(1, `rgba(0,0,0,${0.85 * w.vig})`);
        target.fillStyle = g;
        target.fillRect(-dw / 2, -dh / 2, dw, dh);
      }
    }
    target.restore();

    if (useLayer) {
      if (c.mask.type !== 'none') this.applyMask(c, st, dw, dh);
      const ctx = this.ctx;
      ctx.save();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.globalAlpha = st.alpha;
      ctx.globalCompositeOperation = c.blend as GlobalCompositeOperation;
      ctx.drawImage(this.layer[0], 0, 0);
      ctx.restore();
    }
  }

  private applyMask(c: Clip, st: FrameState, dw: number, dh: number): void {
    const m = c.mask;
    const [mc, mctx] = this.maskC;
    const { W, H, k } = this;
    mctx.setTransform(1, 0, 0, 1, 0, 0);
    mctx.globalCompositeOperation = 'source-over';
    mctx.clearRect(0, 0, mc.width, mc.height);
    mctx.setTransform(k, 0, 0, k, 0, 0);
    mctx.save();
    mctx.translate(W / 2 + st.x, H / 2 + st.y);
    mctx.rotate((st.rot * Math.PI) / 180);
    mctx.translate((m.x * dw) / 2, (m.y * dh) / 2);
    mctx.rotate((m.rotation * Math.PI) / 180);
    const feather = m.feather * Math.min(dw, dh) * 0.2;
    if (feather > 0.5) mctx.filter = `blur(${feather * k}px)`;
    mctx.fillStyle = '#fff';
    mctx.beginPath();
    const s = m.size * Math.min(dw, dh);
    switch (m.type) {
      case 'circle':
        mctx.arc(0, 0, s / 2, 0, Math.PI * 2);
        break;
      case 'rect':
        mctx.rect((-m.size * dw) / 2, (-m.size * dh) / 2, m.size * dw, m.size * dh);
        break;
      case 'linear':
        mctx.rect(-BIG, -BIG, BIG * 2, BIG);
        break;
      case 'mirror':
        mctx.rect(-BIG, (-m.size * dh) / 2, BIG * 2, m.size * dh);
        break;
      case 'heart':
        mctx.moveTo(0, s * 0.38);
        mctx.bezierCurveTo(-s * 0.62, -s * 0.02, -s * 0.38, -s * 0.58, 0, -s * 0.22);
        mctx.bezierCurveTo(s * 0.38, -s * 0.58, s * 0.62, -s * 0.02, 0, s * 0.38);
        break;
      case 'star':
        for (let i = 0; i < 10; i++) {
          const r = i % 2 ? s * 0.22 : s / 2;
          const a = -Math.PI / 2 + (i * Math.PI) / 5;
          if (i === 0) mctx.moveTo(Math.cos(a) * r, Math.sin(a) * r);
          else mctx.lineTo(Math.cos(a) * r, Math.sin(a) * r);
        }
        mctx.closePath();
        break;
    }
    mctx.fill();
    mctx.restore();
    mctx.filter = 'none';
    if (m.invert) {
      mctx.setTransform(1, 0, 0, 1, 0, 0);
      mctx.globalCompositeOperation = 'xor';
      mctx.fillRect(0, 0, mc.width, mc.height);
      mctx.globalCompositeOperation = 'source-over';
    }
    const lctx = this.layer[1];
    lctx.save();
    lctx.setTransform(1, 0, 0, 1, 0, 0);
    lctx.globalCompositeOperation = 'destination-in';
    lctx.drawImage(mc, 0, 0);
    lctx.restore();
  }

  private chromaKey(src: CanvasImageSource, sx: number, sy: number, sw: number, sh: number, ch: Chroma, targetW: number): HTMLCanvasElement {
    const [cc, cctx] = this.chroma;
    const scale = Math.min(1, Math.max(64, targetW) / sw, 1280 / sw);
    const w = Math.max(1, Math.round(sw * scale));
    const h = Math.max(1, Math.round(sh * scale));
    if (cc.width !== w || cc.height !== h) {
      cc.width = w;
      cc.height = h;
    }
    cctx.clearRect(0, 0, w, h);
    cctx.drawImage(src, sx, sy, sw, sh, 0, 0, w, h);
    const img = cctx.getImageData(0, 0, w, h);
    const d = img.data;
    const [kr, kg, kb] = hexToRgb(ch.color);
    const kcb = -0.168736 * kr - 0.331264 * kg + 0.5 * kb;
    const kcr = 0.5 * kr - 0.418688 * kg - 0.081312 * kb;
    const sim = ch.similarity * 150;
    const smooth = ch.smoothness * 100 + 1;
    const greenKey = kg > kr && kg > kb;
    const blueKey = kb > kr && kb > kg;
    for (let i = 0; i < d.length; i += 4) {
      const r = d[i];
      const g = d[i + 1];
      const b = d[i + 2];
      const cb = -0.168736 * r - 0.331264 * g + 0.5 * b;
      const cr = 0.5 * r - 0.418688 * g - 0.081312 * b;
      const dist = Math.hypot(cb - kcb, cr - kcr);
      const a = clamp((dist - sim) / smooth, 0, 1);
      if (a < 1) {
        d[i + 3] = d[i + 3] * a;
        // spill suppression on the semi transparent edge
        if (greenKey) d[i + 1] = Math.min(g, Math.max(r, b));
        else if (blueKey) d[i + 2] = Math.min(b, Math.max(r, g));
      }
    }
    cctx.putImageData(img, 0, 0);
    return cc;
  }

  // ---------------------------------------------------------------- text

  measureText(c: Clip): TextMetrics2 {
    const s = c.text!;
    const key = [s.content, s.font, s.size, s.bold, s.italic, s.letterSpacing, s.lineHeight, s.bg, s.strokeWidth].join('|');
    const hit = this.textCache.get(key);
    if (hit) return hit;
    const m = this.measure;
    m.font = textFont(c);
    (m as unknown as { letterSpacing: string }).letterSpacing = `${s.letterSpacing}px`;
    const lines = s.content.split('\n');
    const widths = lines.map((l) => m.measureText(l || ' ').width);
    const maxW = Math.max(s.size * 0.3, ...widths);
    const lineH = s.size * s.lineHeight;
    const pad = s.bg ? s.size * 0.35 : s.size * 0.08 + s.strokeWidth;
    const r: TextMetrics2 = { w: maxW + pad * 2, h: lines.length * lineH + pad * 2, lines, lineH, pad };
    if (this.textCache.size > 300) this.textCache.clear();
    this.textCache.set(key, r);
    return r;
  }

  clearTextCache(): void {
    this.textCache.clear();
  }

  private drawText(c: Clip, t: number, mods: Mods): void {
    const s = c.text!;
    const st = this.state(c, t, mods);
    if (st.alpha <= 0.001 || !s.content) return;
    const m = this.measureText(c);
    const ctx = this.ctx;
    const k = this.k * st.scale;
    ctx.save();
    if (mods.clip) {
      ctx.beginPath();
      mods.clip(ctx);
      ctx.clip();
    }
    ctx.globalAlpha = st.alpha;
    ctx.globalCompositeOperation = c.blend as GlobalCompositeOperation;
    if (st.blur > 0.05) ctx.filter = `blur(${st.blur * this.k}px)`;
    ctx.translate(this.W / 2 + st.x, this.H / 2 + st.y);
    ctx.rotate((st.rot * Math.PI) / 180);
    ctx.scale(st.scale * (c.flipH ? -1 : 1), st.scale * (c.flipV ? -1 : 1));
    if (s.bg) {
      ctx.fillStyle = rgba(s.bgColor, s.bgOpacity);
      ctx.beginPath();
      ctx.roundRect(-m.w / 2, -m.h / 2, m.w, m.h, s.bgRadius);
      ctx.fill();
    }
    const font = textFont(c);
    if (document.fonts && !document.fonts.check(font)) void document.fonts.load(font).catch(() => undefined);
    ctx.font = font;
    (ctx as unknown as { letterSpacing: string }).letterSpacing = `${s.letterSpacing}px`;
    ctx.textBaseline = 'middle';
    ctx.textAlign = s.align;
    ctx.lineJoin = 'round';
    const x = s.align === 'left' ? -m.w / 2 + m.pad : s.align === 'right' ? m.w / 2 - m.pad : 0;
    const total = m.lines.reduce((n, l) => n + l.length, 0);
    let remaining = st.reveal >= 1 ? Infinity : Math.floor(total * st.reveal);
    m.lines.forEach((line, i) => {
      if (remaining <= 0) return;
      const txt = line.slice(0, remaining);
      remaining -= line.length;
      const y = -m.h / 2 + m.pad + m.lineH * (i + 0.5);
      if (s.shadow) {
        ctx.shadowColor = s.shadowColor;
        ctx.shadowBlur = s.shadowBlur * k;
        ctx.shadowOffsetX = s.shadowX * k;
        ctx.shadowOffsetY = s.shadowY * k;
      }
      if (s.strokeWidth > 0) {
        ctx.lineWidth = s.strokeWidth * 2;
        ctx.strokeStyle = s.strokeColor;
        ctx.strokeText(txt, x, y);
        ctx.shadowColor = 'transparent';
      }
      ctx.fillStyle = s.color;
      ctx.fillText(txt, x, y);
      ctx.shadowColor = 'transparent';
      if (s.underline && txt) {
        const tw = ctx.measureText(txt).width;
        const ux = s.align === 'left' ? x : s.align === 'right' ? x - tw : x - tw / 2;
        ctx.fillRect(ux, y + s.size * 0.42, tw, Math.max(2, s.size * 0.06));
      }
    });
    ctx.restore();
  }

  private drawSticker(c: Clip, t: number, mods: Mods): void {
    const st = this.state(c, t, mods);
    if (st.alpha <= 0.001 || !c.emoji) return;
    const ctx = this.ctx;
    ctx.save();
    if (mods.clip) {
      ctx.beginPath();
      mods.clip(ctx);
      ctx.clip();
    }
    ctx.globalAlpha = st.alpha;
    ctx.globalCompositeOperation = c.blend as GlobalCompositeOperation;
    if (st.blur > 0.05) ctx.filter = `blur(${st.blur * this.k}px)`;
    ctx.translate(this.W / 2 + st.x, this.H / 2 + st.y);
    ctx.rotate((st.rot * Math.PI) / 180);
    ctx.scale(st.scale * (c.flipH ? -1 : 1), st.scale * (c.flipV ? -1 : 1));
    ctx.font = `${STICKER_SIZE}px ${EMOJI_FONT}`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(c.emoji, 0, STICKER_SIZE * 0.06);
    ctx.restore();
  }

  /** Untransformed-by-animation bounds in project coordinates (for selection handles & hit testing). */
  geometry(c: Clip, t: number): Geometry | null {
    const tr = getTransformAt(c, clamp(t - c.start, 0, c.duration));
    let w = 0;
    let h = 0;
    if (c.type === 'video' || c.type === 'image') {
      const media = c.mediaId ? store.media.get(c.mediaId) : undefined;
      const d = this.src.dims(c) ?? (media ? { w: media.width, h: media.height } : null);
      if (!d || !d.w) return null;
      const sw = d.w * (1 - c.crop.l - c.crop.r);
      const sh = d.h * (1 - c.crop.t - c.crop.b);
      const fit = Math.min(store.project.width / sw, store.project.height / sh);
      w = sw * fit * tr.scale;
      h = sh * fit * tr.scale;
    } else if (c.type === 'text') {
      const m = this.measureText(c);
      w = m.w * tr.scale;
      h = m.h * tr.scale;
    } else if (c.type === 'sticker') {
      w = h = STICKER_SIZE * 1.1 * tr.scale;
    } else return null;
    return { cx: store.project.width / 2 + tr.x, cy: store.project.height / 2 + tr.y, w, h, rot: tr.rotation };
  }

  // ---------------------------------------------------------------- transitions

  private drawTransition(tr: Track, a: Clip, b: Clip, p: number, t: number): void {
    const type = b.transition!.type;
    const { W, H } = this;
    const e = easing.inOutCubic(clamp(p, 0, 1));
    const diag = Math.hypot(W, H);
    const draw = (c: Clip, m: Mods = {}) => this.drawClip(c, t, m);
    switch (type) {
      case 'fade':
        draw(a);
        draw(b, { alpha: e });
        break;
      case 'black':
      case 'white': {
        const amt = 1 - Math.abs(2 * p - 1);
        const cur = p < 0.5 ? a : b;
        if (type === 'black' && !tr.main) {
          draw(cur, { alpha: 1 - amt });
        } else {
          draw(cur);
          this.ctx.fillStyle = type === 'black' ? `rgba(0,0,0,${amt})` : `rgba(255,255,255,${amt})`;
          this.ctx.fillRect(0, 0, W, H);
        }
        break;
      }
      case 'wipeLeft':
        draw(a);
        draw(b, { clip: (c) => c.rect(W * (1 - e), 0, W * e + 1, H) });
        break;
      case 'wipeRight':
        draw(a);
        draw(b, { clip: (c) => c.rect(0, 0, W * e, H) });
        break;
      case 'wipeUp':
        draw(a);
        draw(b, { clip: (c) => c.rect(0, H * (1 - e), W, H * e + 1) });
        break;
      case 'wipeDown':
        draw(a);
        draw(b, { clip: (c) => c.rect(0, 0, W, H * e) });
        break;
      case 'slideLeft':
        draw(a, { dx: -W * e });
        draw(b, { dx: W * (1 - e) });
        break;
      case 'slideRight':
        draw(a, { dx: W * e });
        draw(b, { dx: -W * (1 - e) });
        break;
      case 'pushUp':
        draw(a, { dy: -H * e });
        draw(b, { dy: H * (1 - e) });
        break;
      case 'zoomIn':
        draw(a, { scale: 1 + e * 0.8, alpha: 1 - e });
        draw(b, { scale: 0.6 + 0.4 * e, alpha: e });
        break;
      case 'zoomOut':
        draw(a, { scale: 1 - 0.5 * e, alpha: 1 - e });
        draw(b, { scale: 1.5 - 0.5 * e, alpha: e });
        break;
      case 'circle':
        draw(a);
        draw(b, { clip: (c) => c.arc(W / 2, H / 2, Math.max(0.1, (e * diag) / 2), 0, Math.PI * 2) });
        break;
      case 'clock':
        draw(a);
        draw(b, {
          clip: (c) => {
            c.moveTo(W / 2, H / 2);
            c.arc(W / 2, H / 2, diag, -Math.PI / 2, -Math.PI / 2 + Math.max(0.001, e) * Math.PI * 2);
            c.closePath();
          },
        });
        break;
      case 'doors':
        draw(b);
        draw(a, { dx: (-W / 2) * e, clip: (c) => c.rect((-W / 2) * e, 0, W / 2, H) });
        draw(a, { dx: (W / 2) * e, clip: (c) => c.rect(W / 2 + (W / 2) * e, 0, W / 2, H) });
        break;
      case 'blur':
        if (p < 0.5) draw(a, { blur: 50 * p * 2 });
        else draw(b, { blur: 50 * (1 - p) * 2 });
        break;
      case 'spin':
        draw(a, { rot: 180 * e, scale: Math.max(0.01, 1 - e), alpha: 1 - e });
        draw(b, { rot: -180 * (1 - e), scale: Math.max(0.01, e), alpha: e });
        break;
      default:
        draw(p < 0.5 ? a : b);
    }
  }

  // ---------------------------------------------------------------- effects

  private snapshot(): HTMLCanvasElement {
    const [sc, sctx] = this.scratch;
    sctx.setTransform(1, 0, 0, 1, 0, 0);
    sctx.globalCompositeOperation = 'copy';
    sctx.drawImage(this.canvas, 0, 0);
    sctx.globalCompositeOperation = 'source-over';
    return sc;
  }

  private rgbShift(off: number, alpha = 1): void {
    const snap = this.snapshot();
    const ctx = this.ctx;
    const colors = ['#ff0000', '#00ff00', '#0000ff'];
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, snap.width, snap.height);
    ctx.globalCompositeOperation = 'lighter';
    ctx.globalAlpha = alpha;
    this.chan.forEach(([cc, cctx], j) => {
      cctx.setTransform(1, 0, 0, 1, 0, 0);
      cctx.globalCompositeOperation = 'copy';
      cctx.drawImage(snap, 0, 0);
      cctx.globalCompositeOperation = 'multiply';
      cctx.fillStyle = colors[j];
      cctx.fillRect(0, 0, cc.width, cc.height);
      cctx.globalCompositeOperation = 'source-over';
      ctx.drawImage(cc, (j - 1) * off, 0);
    });
    ctx.restore();
  }

  private grainPattern(): HTMLCanvasElement {
    if (this.grain) return this.grain;
    const c = document.createElement('canvas');
    c.width = c.height = 256;
    const g = c.getContext('2d')!;
    const img = g.createImageData(256, 256);
    for (let i = 0; i < img.data.length; i += 4) {
      const v = Math.random() * 255;
      img.data[i] = img.data[i + 1] = img.data[i + 2] = v;
      img.data[i + 3] = 255;
    }
    g.putImageData(img, 0, 0);
    this.grain = c;
    return c;
  }

  private drawGrain(amount: number, frame: number): void {
    const ctx = this.ctx;
    const pat = ctx.createPattern(this.grainPattern(), 'repeat');
    if (!pat) return;
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, rand(frame) * 256, rand(frame + 7) * 256);
    ctx.globalCompositeOperation = 'overlay';
    ctx.globalAlpha = amount;
    ctx.fillStyle = pat;
    ctx.fillRect(-256, -256, this.canvas.width + 512, this.canvas.height + 512);
    ctx.restore();
  }

  private vignette(amount: number): void {
    const ctx = this.ctx;
    const { width: cw, height: ch } = this.canvas;
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    const g = ctx.createRadialGradient(cw / 2, ch / 2, Math.min(cw, ch) * 0.3, cw / 2, ch / 2, Math.hypot(cw, ch) / 2);
    g.addColorStop(0, 'rgba(0,0,0,0)');
    g.addColorStop(1, `rgba(0,0,0,${0.9 * amount})`);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, cw, ch);
    ctx.restore();
  }

  private applyEffect(c: Clip, t: number): void {
    const id = c.effect?.id;
    if (!id || !EFFECTS.some((e) => e.id === id)) return;
    const i = c.effect!.intensity;
    const local = t - c.start;
    const frame = Math.floor(t * 30);
    const ctx = this.ctx;
    const cw = this.canvas.width;
    const ch = this.canvas.height;
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    switch (id) {
      case 'shake': {
        const snap = this.snapshot();
        const amp = 0.035 * i;
        const dx = (rand(frame) - 0.5) * cw * amp;
        const dy = (rand(frame + 3) - 0.5) * ch * amp;
        const s = 1 + amp;
        ctx.drawImage(snap, (cw - cw * s) / 2 + dx, (ch - ch * s) / 2 + dy, cw * s, ch * s);
        break;
      }
      case 'zoomPulse': {
        const snap = this.snapshot();
        const beat = (local * 2) % 1;
        const z = 1 + 0.12 * i * Math.pow(1 - beat, 3);
        ctx.drawImage(snap, (cw - cw * z) / 2, (ch - ch * z) / 2, cw * z, ch * z);
        break;
      }
      case 'blur': {
        const snap = this.snapshot();
        ctx.globalCompositeOperation = 'screen';
        ctx.globalAlpha = 0.65 * i;
        ctx.filter = `blur(${cw * 0.012}px)`;
        ctx.drawImage(snap, 0, 0);
        break;
      }
      case 'mirror': {
        const snap = this.snapshot();
        ctx.translate(cw, 0);
        ctx.scale(-1, 1);
        ctx.drawImage(snap, 0, 0, cw / 2, ch, 0, 0, cw / 2, ch);
        break;
      }
      case 'rgbSplit':
        ctx.restore();
        this.rgbShift(cw * 0.008 * i * (1 + Math.sin(local * 6) * 0.5));
        return;
      case 'glitch': {
        ctx.restore();
        const active = rand(Math.floor(t * 8)) < 0.35 + 0.4 * i;
        if (!active) return;
        this.rgbShift(cw * 0.012 * i * rand(frame));
        const snap = this.snapshot();
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        const n = 3 + Math.floor(rand(frame + 1) * 6);
        for (let j = 0; j < n; j++) {
          const y = rand(frame * 13 + j) * ch;
          const hh = rand(frame * 7 + j) * ch * 0.08 + 2;
          const off = (rand(frame * 3 + j) - 0.5) * cw * 0.12 * i;
          ctx.drawImage(snap, 0, y, cw, hh, off, y, cw, hh);
        }
        break;
      }
      case 'vhs': {
        ctx.restore();
        this.rgbShift(cw * 0.004 * i);
        ctx.save();
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.fillStyle = `rgba(0,0,0,${0.22 * i})`;
        const step = Math.max(2, Math.round(ch / 270));
        for (let y = 0; y < ch; y += step * 2) ctx.fillRect(0, y, cw, step);
        const band = ((local * 0.25) % 1) * ch;
        ctx.fillStyle = `rgba(255,255,255,${0.08 * i})`;
        ctx.fillRect(0, band, cw, ch * 0.04);
        ctx.font = `${Math.round(ch * 0.05)}px "Roboto Mono", monospace`;
        ctx.fillStyle = 'rgba(255,255,255,0.85)';
        ctx.textBaseline = 'top';
        ctx.fillText('PLAY ▶', cw * 0.05, ch * 0.06);
        break;
      }
      case 'vignette':
        ctx.restore();
        this.vignette(i);
        return;
      case 'grain':
        ctx.restore();
        this.drawGrain(0.4 * i, frame);
        return;
      case 'oldFilm': {
        const snap = this.snapshot();
        ctx.filter = `sepia(${0.85 * i}) contrast(1.1) brightness(${1 - 0.12 * rand(frame) * i})`;
        ctx.drawImage(snap, 0, 0);
        ctx.filter = 'none';
        ctx.strokeStyle = `rgba(20,10,0,${0.5 * i})`;
        ctx.lineWidth = Math.max(1, cw / 900);
        for (let j = 0; j < 3; j++) {
          if (rand(frame * 5 + j) < 0.5) continue;
          const x = rand(frame * 11 + j) * cw;
          ctx.beginPath();
          ctx.moveTo(x, 0);
          ctx.lineTo(x + (rand(frame + j) - 0.5) * 20, ch);
          ctx.stroke();
        }
        ctx.restore();
        this.drawGrain(0.45 * i, frame);
        this.vignette(0.6 * i);
        return;
      }
      case 'flash': {
        const a = Math.max(0, 1 - ((local * 4) % 1) * 3) * 0.85 * i;
        ctx.fillStyle = `rgba(255,255,255,${a})`;
        ctx.fillRect(0, 0, cw, ch);
        break;
      }
      case 'letterbox': {
        const grow = easing.outCubic(clamp(local / 0.6, 0, 1)) * easing.outCubic(clamp((c.duration - local) / 0.6, 0, 1));
        const bar = ch * 0.13 * i * grow;
        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, cw, bar);
        ctx.fillRect(0, ch - bar, cw, bar);
        break;
      }
      case 'lightLeak': {
        const x = cw * (0.5 + 0.6 * Math.sin(local * 0.9));
        const y = ch * (0.3 + 0.3 * Math.cos(local * 0.6));
        const g = ctx.createRadialGradient(x, y, 0, x, y, Math.max(cw, ch) * 0.7);
        g.addColorStop(0, `rgba(255,170,60,${0.75 * i})`);
        g.addColorStop(0.4, `rgba(255,60,90,${0.35 * i})`);
        g.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.globalCompositeOperation = 'screen';
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, cw, ch);
        break;
      }
      case 'snow': {
        const n = Math.round(60 + 200 * i);
        ctx.fillStyle = 'rgba(255,255,255,0.85)';
        for (let j = 0; j < n; j++) {
          const r = (1 + rand(j * 3) * 3) * (cw / 960);
          const speed = 0.08 + rand(j * 5) * 0.18;
          const y = ((rand(j * 7) + local * speed) % 1) * (ch + 20) - 10;
          const x = rand(j * 11) * cw + Math.sin(local * 1.5 + j) * cw * 0.01;
          ctx.beginPath();
          ctx.arc(x, y, r, 0, Math.PI * 2);
          ctx.fill();
        }
        break;
      }
      case 'mono': {
        const snap = this.snapshot();
        ctx.filter = `grayscale(${i}) contrast(${1 + 0.1 * i})`;
        ctx.globalCompositeOperation = 'copy';
        ctx.drawImage(snap, 0, 0);
        break;
      }
      case 'pixelate': {
        const snap = this.snapshot();
        const block = Math.max(2, Math.round(cw * 0.025 * i));
        const [tc, tctx] = this.tiny;
        tc.width = Math.max(1, Math.round(cw / block));
        tc.height = Math.max(1, Math.round(ch / block));
        tctx.drawImage(snap, 0, 0, tc.width, tc.height);
        ctx.imageSmoothingEnabled = false;
        ctx.drawImage(tc, 0, 0, cw, ch);
        ctx.imageSmoothingEnabled = true;
        break;
      }
    }
    ctx.restore();
  }
}
