// Right-hand properties panel. Builds a tabbed form for the current selection.
import type { Adjust, Clip, MaskType, TextStyle } from './types';
import * as A from './actions';
import { button, color, custom, group, note, row, seg, select, slider, tiles, textarea, toggle, type Ctl } from './controls';
import { engine } from './engine';
import { icon } from './icons';
import { clipEnd, getTransformAt, KF_TOL, keyframeAt, maxDuration, prevAdjacent } from './model';
import {
  ANIMATIONS_IN,
  ANIMATIONS_OUT,
  ASPECTS,
  BLEND_MODES,
  DEFAULT_TEXT,
  EFFECTS,
  FILTERS,
  FONTS,
  TEXT_PRESETS,
  TRANSITIONS,
  ZERO_ADJUST,
  ANIM_GLYPH,
  TRANS_GLYPH,
  cssFilter,
  fontCss,
  textPreviewStyle,
} from './presets';
import { store } from './store';
import { $, clamp, h, shortTime, toast } from './utils';

type TabId = 'basic' | 'cutout' | 'animation' | 'speed' | 'audio' | 'adjust' | 'filter' | 'text' | 'effect';

const TAB_META: Record<TabId, { label: string; icon: string }> = {
  text: { label: 'Text', icon: 'type' },
  basic: { label: 'Basic', icon: 'layers' },
  cutout: { label: 'Cutout', icon: 'mask' },
  animation: { label: 'Animate', icon: 'sparkles' },
  speed: { label: 'Speed', icon: 'speed' },
  audio: { label: 'Audio', icon: 'volume' },
  adjust: { label: 'Adjust', icon: 'sliders' },
  filter: { label: 'Filters', icon: 'filter' },
  effect: { label: 'Effect', icon: 'sparkles' },
};

const TYPE_ICON: Record<string, string> = { video: 'film', image: 'image', audio: 'music', text: 'type', sticker: 'smile', effect: 'sparkles' };

function tabsFor(c: Clip): TabId[] {
  switch (c.type) {
    case 'video': {
      const m = c.mediaId ? store.media.get(c.mediaId) : undefined;
      return ['basic', 'cutout', 'animation', 'speed', ...(m?.hasAudio ? (['audio'] as TabId[]) : []), 'adjust', 'filter'];
    }
    case 'image':
      return ['basic', 'cutout', 'animation', 'adjust', 'filter'];
    case 'text':
      return ['text', 'basic', 'animation'];
    case 'sticker':
      return ['basic', 'animation'];
    case 'audio':
      return ['audio', 'speed'];
    case 'effect':
      return ['effect'];
  }
}

export class Inspector {
  private head = $('#insp-head');
  private tabsEl = $('#insp-tabs');
  private body = $('#insp-body');
  private ctls: Ctl[] = [];
  private sig = '';
  private tabByType: Record<string, TabId> = {};
  private lastTimeRefresh = 0;

  constructor() {
    store.on((r) => {
      if (r === 'selection' || r === 'load') this.build();
      else if (r === 'project' || r === 'media') this.refresh();
      else if (r === 'time') this.onTime();
    });
    addEventListener('inspector:tab', (e) => {
      const c = store.selectedClip;
      const tab = (e as CustomEvent<string>).detail as TabId;
      if (c && tabsFor(c).includes(tab)) {
        this.tabByType[c.type] = tab;
        this.build(true);
        if (tab === 'text') setTimeout(() => document.getElementById('text-content')?.focus(), 0);
      }
    });
    this.build();
  }

  private signature(): string {
    const s = store.selection;
    if (s.kind === 'none') return `none:${store.project.id}:${store.project.aspect}:${store.project.fps}`;
    if (s.kind === 'transition') return `tr:${s.id}`;
    if (s.ids.length > 1) return `multi:${s.ids.join(',')}`;
    const c = store.clip(s.ids[0]);
    if (!c) return 'none';
    return `clip:${c.id}:${c.type}:${tabsFor(c).join(',')}:${this.tabByType[c.type] ?? ''}`;
  }

  private refresh(): void {
    if (this.signature() !== this.sig) this.build();
    else this.ctls.forEach((c) => c.update());
  }

  private onTime(): void {
    const c = store.selectedClip;
    if (!c || !c.keyframes.length) {
      this.updateKfButtons();
      return;
    }
    const now = performance.now();
    if (store.playing && now - this.lastTimeRefresh < 120) return;
    this.lastTimeRefresh = now;
    this.ctls.forEach((x) => x.update());
  }

  private updateKfButtons(): void {
    const c = store.selectedClip;
    const b = this.body.querySelector<HTMLElement>('[data-kf-toggle]');
    if (!c || !b) return;
    const on = !!keyframeAt(c, store.time - c.start);
    b.innerHTML = icon(on ? 'diamondFill' : 'diamond', 15);
    b.classList.toggle('is-on', on);
  }

  build(keepScroll = false): void {
    const scroll = keepScroll ? this.body.scrollTop : 0;
    this.sig = this.signature();
    this.ctls = [];
    this.body.replaceChildren();
    this.tabsEl.replaceChildren();
    this.tabsEl.hidden = true;
    const s = store.selection;
    if (s.kind === 'none') this.buildProject();
    else if (s.kind === 'transition') this.buildTransition(s.id);
    else if (s.ids.length > 1) this.buildMulti(s.ids);
    else {
      const c = store.clip(s.ids[0]);
      if (c) this.buildClip(c);
      else this.buildProject();
    }
    this.body.scrollTop = scroll;
  }

  private setHead(iconName: string, title: string, sub = ''): void {
    this.head.innerHTML = `<span class="insp-head-ic">${icon(iconName, 18)}</span><div class="insp-head-text"><strong></strong><small></small></div>`;
    this.head.querySelector('strong')!.textContent = title;
    this.head.querySelector('small')!.textContent = sub;
  }

  private add(...ctls: (Ctl | null)[]): void {
    for (const c of ctls) {
      if (!c) continue;
      this.ctls.push(c);
      this.body.append(c.el);
      c.update();
    }
  }

  // ------------------------------------------------------------ project

  private buildProject(): void {
    const p = () => store.project;
    this.setHead('settings', 'Project', `${store.project.width}×${store.project.height} · ${store.project.fps} fps`);
    const name = h('input', { type: 'text', class: 'text-input', 'aria-label': 'Project name', maxlength: 80 });
    name.addEventListener('change', () => store.commit((pr) => (pr.name = name.value.trim() || 'Untitled project')));
    const ratio = tiles({
      cls: 'tiles-ratio',
      items: ASPECTS.map((a) => {
        const s = 26 / Math.max(a.w, a.h);
        return { id: a.id, label: `${a.label}`, preview: `<i style="width:${a.w * s}px;height:${a.h * s}px"></i><em>${a.hint}</em>` };
      }),
      get: () => p().aspect,
      set: (v) => {
        const a = ASPECTS.find((x) => x.id === v)!;
        store.commit((pr) => {
          pr.aspect = a.id;
          pr.width = a.w;
          pr.height = a.h;
        });
      },
    });
    const stats = h('dl', { class: 'stats' });
    const updStats = () => {
      const pr = p();
      stats.innerHTML = `<div><dt>Duration</dt><dd>${shortTime(store.duration)}</dd></div><div><dt>Clips</dt><dd>${pr.clips.length}</dd></div><div><dt>Tracks</dt><dd>${pr.tracks.length}</dd></div><div><dt>Media</dt><dd>${store.media.size}</dd></div>`;
    };
    this.add(
      group('Name', [custom(name, () => document.activeElement !== name && (name.value = p().name))]),
      group('Canvas ratio', [ratio]),
      group('Background', [
        seg({ options: [{ id: 'color', label: 'Colour' }, { id: 'blur', label: 'Blur' }], get: () => p().background.type, set: (v) => store.commit((pr) => (pr.background.type = v as 'color' | 'blur')) }),
        color({ label: 'Colour', get: () => p().background.color, set: (v) => store.commit((pr) => (pr.background.color = v), 'bg-color') }),
        slider({ label: 'Blur amount', min: 5, max: 100, step: 1, get: () => p().background.blur, set: (v) => store.commit((pr) => (pr.background.blur = v), 'bg-blur'), unit: 'px' }),
        note('Blur fills empty space behind the main-track clip with a blurred copy of itself.'),
      ]),
      group('Frame rate', [
        select({ label: 'FPS', options: [24, 25, 30, 50, 60].map((f) => ({ id: String(f), label: `${f} fps` })), get: () => String(p().fps), set: (v) => store.commit((pr) => (pr.fps = +v)) }),
      ]),
      group('Overview', [custom(stats, updStats)]),
      group('Tips', [
        note('Import media from the left panel, then drag it to the timeline. Select a clip to edit it here. Press ? for keyboard shortcuts.'),
      ]),
    );
  }

  // ------------------------------------------------------------ multi / transition

  private buildMulti(ids: string[]): void {
    this.setHead('layers', `${ids.length} clips selected`, 'Bulk actions');
    this.add(
      group('Actions', [
        row(
          button('Duplicate', 'copy', () => A.duplicateSelection()),
          button('Delete', 'trash', () => A.deleteSelection(), 'is-danger'),
        ),
      ]),
      group('Volume', [
        slider({ label: 'Volume', min: 0, max: 2, mul: 100, unit: '%', get: () => store.clip(ids[0])?.volume ?? 1, set: (v) => A.updateClips(ids, (c) => (c.volume = v), 'multi-vol') }),
      ]),
    );
  }

  private buildTransition(id: string): void {
    const c = () => store.clip(id);
    const cur = c();
    if (!cur?.transition) return this.buildProject();
    const prev = prevAdjacent(store.project, cur);
    this.setHead('transition', 'Transition', prev ? `${prev.name} → ${cur.name}` : cur.name);
    const maxD = () => {
      const cc = c();
      const pp = cc ? prevAdjacent(store.project, cc) : undefined;
      return cc && pp ? Math.max(0.1, Math.min(3, cc.duration, pp.duration)) : 1;
    };
    this.add(
      group('Style', [
        tiles({
          cls: 'tiles-glyph',
          items: [{ id: 'none', label: 'None' }, ...TRANSITIONS].map((t) => ({ id: t.id, label: t.label, preview: `<b>${TRANS_GLYPH[t.id] ?? '◇'}</b>` })),
          get: () => c()?.transition?.type ?? 'none',
          set: (v) => {
            A.setTransition(id, v);
            if (v === 'none') store.select({ kind: 'none' });
          },
        }),
      ]),
      group('Timing', [
        slider({ label: 'Duration', min: 0.1, max: 3, step: 0.05, unit: 's', get: () => c()?.transition?.duration ?? 0.6, set: (v) => A.setTransition(id, c()?.transition?.type ?? 'fade', Math.min(v, maxD())) }),
        row(button('Apply to all cuts', 'check', () => {
          const t = c()?.transition;
          if (t) A.applyTransitionToAll(t.type, t.duration);
        })),
      ]),
    );
  }

  // ------------------------------------------------------------ clip

  private buildClip(c: Clip): void {
    const id = c.id;
    const tabs = tabsFor(c);
    let tab = this.tabByType[c.type];
    if (!tab || !tabs.includes(tab)) tab = tabs[0];
    const media = c.mediaId ? store.media.get(c.mediaId) : undefined;
    const sub =
      c.type === 'video' || c.type === 'audio'
        ? `${shortTime(c.duration)} · source ${shortTime(media?.duration ?? 0)}${media?.width ? ` · ${media.width}×${media.height}` : ''}`
        : `${shortTime(c.duration)} on timeline`;
    this.setHead(TYPE_ICON[c.type], c.type === 'text' ? 'Text' : c.name, sub);
    if (tabs.length > 1) {
      this.tabsEl.hidden = false;
      for (const t of tabs) {
        const b = h('button', { type: 'button', role: 'tab', class: 'insp-tab', 'aria-selected': String(t === tab), html: `${icon(TAB_META[t].icon, 16)}<span>${TAB_META[t].label}</span>` });
        b.addEventListener('click', () => {
          this.tabByType[c.type] = t;
          this.build();
        });
        this.tabsEl.append(b);
      }
    }
    const get = () => store.clip(id)!;
    switch (tab) {
      case 'basic':
        return this.tabBasic(id, get);
      case 'cutout':
        return this.tabCutout(id, get);
      case 'animation':
        return this.tabAnimation(id, get);
      case 'speed':
        return this.tabSpeed(id, get);
      case 'audio':
        return this.tabAudio(id, get);
      case 'adjust':
        return this.tabAdjust(id, get);
      case 'filter':
        return this.tabFilter(id, get);
      case 'text':
        return this.tabText(id, get);
      case 'effect':
        return this.tabEffect(id, get);
    }
  }

  private tf(get: () => Clip) {
    const c = get();
    return getTransformAt(c, store.time - c.start);
  }

  private tabBasic(id: string, get: () => Clip): void {
    const W = store.project.width;
    const H = store.project.height;
    const isMedia = get().type === 'video' || get().type === 'image';
    const kfToggle = h('button', { type: 'button', class: 'icon-btn kf-btn', 'data-kf-toggle': '', title: 'Add / remove keyframe at playhead', html: icon('diamond', 15) });
    kfToggle.addEventListener('click', () => A.toggleKeyframeAtPlayhead(id));
    const nav = (dir: -1 | 1) => {
      const c = get();
      const local = store.time - c.start;
      const ks = c.keyframes.map((k) => k.t);
      const target = dir < 0 ? ks.filter((t) => t < local - KF_TOL).pop() : ks.find((t) => t > local + KF_TOL);
      if (target !== undefined) engine.seek(c.start + target);
    };
    const prevB = h('button', { type: 'button', class: 'icon-btn', title: 'Previous keyframe', html: icon('stepBack', 15) });
    const nextB = h('button', { type: 'button', class: 'icon-btn', title: 'Next keyframe', html: icon('stepFwd', 15) });
    prevB.addEventListener('click', () => nav(-1));
    nextB.addEventListener('click', () => nav(1));
    const kfBar = h('span', { class: 'kf-bar' }, prevB, kfToggle, nextB);
    const kfInfo = h('p', { class: 'insp-note' });
    const updKf = () => {
      const c = get();
      this.updateKfButtons();
      const inside = store.time >= c.start && store.time <= clipEnd(c);
      kfInfo.textContent = c.keyframes.length
        ? `${c.keyframes.length} keyframe${c.keyframes.length > 1 ? 's' : ''} — edits at the playhead create or update a keyframe.${inside ? '' : ' Move the playhead over the clip to edit.'}`
        : 'Add a keyframe (◆) to animate position, scale, rotation and opacity over time.';
    };
    const S = (prop: 'scale' | 'x' | 'y' | 'rotation' | 'opacity', label: string, min: number, max: number, step: number, mul = 1, unit = '') =>
      slider({ label, min, max, step, mul, unit, get: () => this.tf(get)[prop], set: (v) => A.setTransformProp(id, prop, v) });

    const fillScale = () => {
      const c = get();
      const d = engine.dims(c);
      if (!d) return 1;
      const sw = d.w * (1 - c.crop.l - c.crop.r);
      const sh = d.h * (1 - c.crop.t - c.crop.b);
      return Math.max(W / sw, H / sh) / Math.min(W / sw, H / sh);
    };
    this.add(
      group(
        'Transform',
        [
          S('scale', 'Scale', 0.05, 5, 0.01, 100, '%'),
          S('x', 'Position X', -W, W, 1, 1, 'px'),
          S('y', 'Position Y', -H, H, 1, 1, 'px'),
          S('rotation', 'Rotation', -360, 360, 0.5, 1, '°'),
          S('opacity', 'Opacity', 0, 1, 0.01, 100, '%'),
          custom(kfInfo, updKf),
          row(
            button('Flip H', 'flipH', () => A.updateClip(id, (c) => (c.flipH = !c.flipH))),
            button('Flip V', 'flipV', () => A.updateClip(id, (c) => (c.flipV = !c.flipV))),
            button('Rotate', 'rotate', () => A.setTransformProp(id, 'rotation', ((Math.round(this.tf(get).rotation / 90) * 90 + 90 + 180) % 360) - 180)),
          ),
          isMedia
            ? row(
                button('Fit', 'fit', () => A.updateClip(id, (c) => Object.assign(c.transform, { scale: 1, x: 0, y: 0 }))),
                button('Fill', 'maximize', () => A.updateClip(id, (c) => Object.assign(c.transform, { scale: fillScale(), x: 0, y: 0 }))),
                button('Reset', 'reset', () => A.updateClip(id, (c) => {
                  c.transform = { x: 0, y: 0, scale: 1, rotation: 0, opacity: 1 };
                  c.keyframes = [];
                  c.flipH = c.flipV = false;
                })),
              )
            : row(button('Reset', 'reset', () => A.updateClip(id, (c) => {
                c.transform = { x: 0, y: 0, scale: 1, rotation: 0, opacity: 1 };
                c.keyframes = [];
              }))),
        ],
        kfBar,
      ),
      group('Blend', [
        select({ label: 'Mode', options: BLEND_MODES, get: () => get().blend, set: (v) => A.updateClip(id, (c) => (c.blend = v)) }),
      ]),
    );
    updKf();
  }

  private tabCutout(id: string, get: () => Clip): void {
    const crop = (k: 'l' | 't' | 'r' | 'b', label: string) =>
      slider({
        label,
        min: 0,
        max: 0.9,
        step: 0.005,
        mul: 100,
        unit: '%',
        get: () => get().crop[k],
        set: (v) =>
          A.updateClip(id, (c) => {
            const opp = k === 'l' ? 'r' : k === 'r' ? 'l' : k === 't' ? 'b' : 't';
            c.crop[k] = Math.min(v, 0.95 - c.crop[opp]);
          }, `crop:${id}:${k}`),
      });
    const maskIcons: Record<MaskType, string> = {
      none: '<b>∅</b>',
      linear: '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="9" fill="currentColor"/><path d="M3 12h18" stroke="currentColor"/></svg>',
      mirror: '<svg viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="8" fill="currentColor"/></svg>',
      circle: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" fill="currentColor"/></svg>',
      rect: '<svg viewBox="0 0 24 24"><rect x="5" y="5" width="14" height="14" fill="currentColor"/></svg>',
      heart: '<svg viewBox="0 0 24 24"><path d="M12 20s-7-4.5-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.5-7 10-7 10z" fill="currentColor"/></svg>',
      star: '<svg viewBox="0 0 24 24"><path d="m12 3 2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.4 6.7 19.4l1.2-6L3.4 9.3l6-.7z" fill="currentColor"/></svg>',
    };
    const M = (k: 'x' | 'y' | 'size' | 'rotation' | 'feather', label: string, min: number, max: number, step: number, mul = 1, unit = '') =>
      slider({ label, min, max, step, mul, unit, get: () => get().mask[k], set: (v) => A.updateClip(id, (c) => (c.mask[k] = v), `mask:${id}:${k}`) });
    let picking = false;
    const pickBtn = button('Pick colour from preview', 'wand', () => {
      if (picking) return;
      picking = true;
      pickBtn.classList.add('is-active');
      A.updateClip(id, (c) => (c.chroma.enabled = false));
      toast('Click the colour to remove in the preview');
      const wrap = $('#canvas-wrap');
      wrap.classList.add('is-picking');
      const onPick = (e: PointerEvent) => {
        e.stopPropagation();
        e.preventDefault();
        const canvas = $<HTMLCanvasElement>('#preview-canvas');
        const r = canvas.getBoundingClientRect();
        const x = Math.round(((e.clientX - r.left) / r.width) * canvas.width);
        const y = Math.round(((e.clientY - r.top) / r.height) * canvas.height);
        const d = canvas.getContext('2d')!.getImageData(clamp(x, 0, canvas.width - 1), clamp(y, 0, canvas.height - 1), 1, 1).data;
        const hex = `#${[d[0], d[1], d[2]].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
        A.updateClip(id, (c) => Object.assign(c.chroma, { color: hex, enabled: true }));
        wrap.classList.remove('is-picking');
        pickBtn.classList.remove('is-active');
        picking = false;
      };
      wrap.addEventListener('pointerdown', onPick, { capture: true, once: true });
    });
    this.add(
      group('Crop', [
        crop('l', 'Left'),
        crop('r', 'Right'),
        crop('t', 'Top'),
        crop('b', 'Bottom'),
        row(button('Reset crop', 'reset', () => A.updateClip(id, (c) => (c.crop = { l: 0, t: 0, r: 0, b: 0 })))),
      ]),
      group('Mask', [
        tiles({
          cls: 'tiles-mask',
          items: (Object.keys(maskIcons) as MaskType[]).map((m) => ({ id: m, label: m === 'rect' ? 'Rectangle' : m[0].toUpperCase() + m.slice(1), preview: maskIcons[m] })),
          get: () => get().mask.type,
          set: (v) => A.updateClip(id, (c) => (c.mask.type = v as MaskType)),
        }),
        M('size', 'Size', 0.05, 2, 0.01, 100, '%'),
        M('feather', 'Feather', 0, 1, 0.01, 100, '%'),
        M('x', 'Offset X', -1, 1, 0.01, 100, '%'),
        M('y', 'Offset Y', -1, 1, 0.01, 100, '%'),
        M('rotation', 'Rotation', -180, 180, 1, 1, '°'),
        toggle({ label: 'Invert mask', get: () => get().mask.invert, set: (v) => A.updateClip(id, (c) => (c.mask.invert = v)) }),
      ]),
      group('Chroma key', [
        toggle({ label: 'Remove a colour', hint: 'Green / blue screen', get: () => get().chroma.enabled, set: (v) => A.updateClip(id, (c) => (c.chroma.enabled = v)) }),
        color({ label: 'Key colour', get: () => get().chroma.color, set: (v) => A.updateClip(id, (c) => (c.chroma.color = v), `chroma:${id}`) }),
        row(pickBtn),
        slider({ label: 'Strength', min: 0, max: 1, mul: 100, unit: '%', get: () => get().chroma.similarity, set: (v) => A.updateClip(id, (c) => (c.chroma.similarity = v), `chroma:${id}:s`) }),
        slider({ label: 'Edge softness', min: 0, max: 1, mul: 100, unit: '%', get: () => get().chroma.smoothness, set: (v) => A.updateClip(id, (c) => (c.chroma.smoothness = v), `chroma:${id}:e`) }),
      ]),
    );
  }

  private tabAnimation(id: string, get: () => Clip): void {
    const isText = get().type === 'text';
    const list = (arr: { id: string; label: string }[]) => arr.filter((a) => isText || a.id !== 'typewriter');
    const maxD = () => Math.max(0.1, Math.min(5, get().duration));
    this.add(
      group('In', [
        tiles({
          cls: 'tiles-glyph',
          items: list(ANIMATIONS_IN).map((a) => ({ id: a.id, label: a.label, preview: `<b>${ANIM_GLYPH[a.id] ?? '◇'}</b>` })),
          get: () => get().animIn.type,
          set: (v) => {
            A.updateClip(id, (c) => (c.animIn.type = v));
            if (v !== 'none') engine.seek(get().start);
          },
        }),
        slider({ label: 'Duration', min: 0.1, max: 5, step: 0.05, unit: 's', get: () => get().animIn.duration, set: (v) => A.updateClip(id, (c) => (c.animIn.duration = Math.min(v, maxD())), `ain:${id}`) }),
      ]),
      group('Out', [
        tiles({
          cls: 'tiles-glyph',
          items: list(ANIMATIONS_OUT).map((a) => ({ id: a.id, label: a.label, preview: `<b>${ANIM_GLYPH[a.id] ?? '◇'}</b>` })),
          get: () => get().animOut.type,
          set: (v) => {
            A.updateClip(id, (c) => (c.animOut.type = v));
            if (v !== 'none') engine.seek(Math.max(get().start, clipEnd(get()) - get().animOut.duration - 0.1));
          },
        }),
        slider({ label: 'Duration', min: 0.1, max: 5, step: 0.05, unit: 's', get: () => get().animOut.duration, set: (v) => A.updateClip(id, (c) => (c.animOut.duration = Math.min(v, maxD())), `aout:${id}`) }),
      ]),
    );
  }

  private tabSpeed(id: string, get: () => Clip): void {
    const info = h('p', { class: 'insp-note' });
    const media = () => (get().mediaId ? store.media.get(get().mediaId!) : undefined);
    this.add(
      group('Speed', [
        seg({
          options: [0.25, 0.5, 1, 1.5, 2, 3, 5].map((s) => ({ id: String(s), label: `${s}x` })),
          get: () => String(+get().speed.toFixed(2)),
          set: (v) => A.setSpeed(id, +v),
        }),
        slider({ label: 'Custom', min: 0.1, max: 10, step: 0.05, unit: 'x', get: () => get().speed, set: (v) => A.setSpeed(id, v) }),
        custom(info, () => {
          const c = get();
          info.textContent = `Timeline length ${shortTime(c.duration)} · max ${shortTime(Math.min(maxDuration(c, media()), 36000))}. Pitch is preserved.`;
        }),
      ]),
    );
  }

  private tabAudio(id: string, get: () => Clip): void {
    const fadeMax = () => Math.max(0.1, Math.min(10, get().duration / 2));
    this.add(
      group('Volume', [
        slider({ label: 'Volume', min: 0, max: 2, step: 0.01, mul: 100, unit: '%', get: () => get().volume, set: (v) => A.updateClip(id, (c) => (c.volume = v), `vol:${id}`) }),
        toggle({ label: 'Mute clip', get: () => get().muted, set: (v) => A.updateClip(id, (c) => (c.muted = v)) }),
      ]),
      group('Fade', [
        slider({ label: 'Fade in', min: 0, max: 10, step: 0.05, unit: 's', get: () => get().fadeIn, set: (v) => A.updateClip(id, (c) => (c.fadeIn = Math.min(v, fadeMax())), `fin:${id}`) }),
        slider({ label: 'Fade out', min: 0, max: 10, step: 0.05, unit: 's', get: () => get().fadeOut, set: (v) => A.updateClip(id, (c) => (c.fadeOut = Math.min(v, fadeMax())), `fout:${id}`) }),
      ]),
      get().type === 'video' ? group('Separate', [row(button('Extract audio to track', 'detach', () => A.detachAudio()))]) : null,
    );
  }

  private tabAdjust(id: string, get: () => Clip): void {
    const S = (k: keyof Adjust, label: string, min: number, max: number, mul = 100, unit = '', step = 0.01) =>
      slider({ label, min, max, step, mul, unit, get: () => get().adjust[k], set: (v) => A.updateClip(id, (c) => (c.adjust[k] = v), `adj:${id}:${k}`) });
    this.add(
      group('Light', [S('brightness', 'Brightness', -1, 1), S('contrast', 'Contrast', -1, 1), S('vignette', 'Vignette', 0, 1)]),
      group('Colour', [S('saturation', 'Saturation', -1, 1), S('hue', 'Hue', -180, 180, 1, '°', 1), S('temperature', 'Temperature', -1, 1), S('tint', 'Tint', -1, 1)]),
      group('Detail', [S('blur', 'Blur', 0, 40, 1, 'px', 0.5)]),
      group('', [
        row(
          button('Reset', 'reset', () => A.updateClip(id, (c) => (c.adjust = { ...ZERO_ADJUST }))),
          button('Apply to all', 'check', () => {
            const adj = { ...get().adjust };
            const ids = store.project.clips.filter((c) => c.type === 'video' || c.type === 'image').map((c) => c.id);
            A.updateClips(ids, (c) => (c.adjust = { ...adj }));
            toast(`Adjustments applied to ${ids.length} clips`, 'success');
          }),
        ),
      ]),
    );
  }

  private tabFilter(id: string, get: () => Clip): void {
    const media = get().mediaId ? store.media.get(get().mediaId!) : undefined;
    const thumb = media?.thumbs[Math.floor(media.thumbs.length / 2)] ?? '';
    this.add(
      group('Filters', [
        tiles({
          cls: 'tiles-filter',
          items: FILTERS.map((f) => ({
            id: f.id,
            label: f.label,
            style: `background-image:url("${thumb}");filter:${f.id === 'none' ? 'none' : cssFilter({}, f, 1)}`,
          })),
          get: () => get().filter.id,
          set: (v) => A.updateClip(id, (c) => (c.filter.id = v)),
        }),
        slider({ label: 'Intensity', min: 0, max: 1, mul: 100, unit: '%', get: () => get().filter.intensity, set: (v) => A.updateClip(id, (c) => (c.filter.intensity = v), `fi:${id}`) }),
        row(
          button('Apply to all', 'check', () => {
            const f = { ...get().filter };
            const ids = store.project.clips.filter((c) => c.type === 'video' || c.type === 'image').map((c) => c.id);
            A.updateClips(ids, (c) => (c.filter = { ...f }));
            toast(`Filter applied to ${ids.length} clips`, 'success');
          }),
        ),
      ]),
    );
  }

  private tabText(id: string, get: () => Clip): void {
    const T = () => get().text!;
    const set = (fn: (t: TextStyle) => void, key = '') =>
      A.updateClip(id, (c) => {
        fn(c.text!);
        c.name = c.text!.content.split('\n')[0].slice(0, 40) || 'Text';
      }, key);
    const S = (k: 'size' | 'letterSpacing' | 'lineHeight' | 'strokeWidth' | 'shadowBlur' | 'shadowX' | 'shadowY' | 'bgOpacity' | 'bgRadius', label: string, min: number, max: number, step = 1, mul = 1, unit = '') =>
      slider({ label, min, max, step, mul, unit, get: () => T()[k], set: (v) => set((t) => (t[k] = v), `txt:${id}:${k}`) });
    const fontSel = select({ label: 'Font', options: FONTS.map((f) => ({ id: f.id, label: f.label })), get: () => T().font, set: (v) => set((t) => (t.font = v)) });
    (fontSel.el.querySelector('select') as HTMLSelectElement).querySelectorAll('option').forEach((o) => (o.style.fontFamily = fontCss(o.value)));
    const styleBtns = h('div', { class: 'seg' });
    const styleDefs: { k: 'bold' | 'italic' | 'underline'; ic: string }[] = [
      { k: 'bold', ic: 'bold' },
      { k: 'italic', ic: 'italic' },
      { k: 'underline', ic: 'underline' },
    ];
    const sb = styleDefs.map(({ k, ic }) => {
      const b = h('button', { type: 'button', class: 'seg-btn', title: k, html: icon(ic, 16) });
      b.addEventListener('click', () => set((t) => (t[k] = !t[k])));
      styleBtns.append(b);
      return [k, b] as const;
    });
    const updStyle = () => sb.forEach(([k, b]) => b.setAttribute('aria-pressed', String(T()[k])));
    this.add(
      group('Content', [textarea({ label: 'Text', get: () => T().content, set: (v) => set((t) => (t.content = v), `txt:${id}:content`) })]),
      group('Styles', [
        tiles({
          cls: 'tiles-text',
          items: TEXT_PRESETS.map((p) => ({ id: p.id, label: p.label, preview: `<span style="${textPreviewStyle(p.style)}">Aa</span>` })),
          get: () => '',
          set: (v) => {
            const p = TEXT_PRESETS.find((x) => x.id === v)!;
            set((t) => Object.assign(t, { ...DEFAULT_TEXT, ...p.style, content: t.content }));
          },
        }),
      ]),
      group('Font', [
        fontSel,
        S('size', 'Size', 12, 400, 1, 1, 'px'),
        color({ label: 'Colour', get: () => T().color, set: (v) => set((t) => (t.color = v), `txt:${id}:color`) }),
        custom(h('div', { class: 'ctl ctl-inline' }, h('span', { class: 'ctl-label' }, 'Style'), styleBtns), updStyle),
        seg({
          label: 'Align',
          options: [
            { id: 'left', label: 'Left', icon: 'alignLeft' },
            { id: 'center', label: 'Center', icon: 'alignCenter' },
            { id: 'right', label: 'Right', icon: 'alignRight' },
          ],
          get: () => T().align,
          set: (v) => set((t) => (t.align = v as TextStyle['align'])),
        }),
        S('letterSpacing', 'Letter spacing', -10, 60, 1, 1, 'px'),
        S('lineHeight', 'Line height', 0.7, 3, 0.05, 1, '×'),
      ]),
      group('Stroke', [
        color({ label: 'Colour', get: () => T().strokeColor, set: (v) => set((t) => (t.strokeColor = v), `txt:${id}:sc`) }),
        S('strokeWidth', 'Width', 0, 30, 0.5, 1, 'px'),
      ]),
      group('Shadow', [
        toggle({ label: 'Shadow', get: () => T().shadow, set: (v) => set((t) => (t.shadow = v)) }),
        color({ label: 'Colour', get: () => T().shadowColor, set: (v) => set((t) => (t.shadowColor = v), `txt:${id}:shc`) }),
        S('shadowBlur', 'Blur', 0, 100),
        S('shadowX', 'Offset X', -50, 50),
        S('shadowY', 'Offset Y', -50, 50),
      ]),
      group('Background', [
        toggle({ label: 'Label background', get: () => T().bg, set: (v) => set((t) => (t.bg = v)) }),
        color({ label: 'Colour', get: () => T().bgColor, set: (v) => set((t) => (t.bgColor = v), `txt:${id}:bgc`) }),
        S('bgOpacity', 'Opacity', 0, 1, 0.01, 100, '%'),
        S('bgRadius', 'Corner radius', 0, 100),
      ]),
    );
    updStyle();
  }

  private tabEffect(id: string, get: () => Clip): void {
    this.add(
      group('Effect', [
        tiles({
          cls: 'tiles-fx',
          items: EFFECTS.map((e) => ({ id: e.id, label: e.label, style: `--h:${e.hue}`, icon: 'sparkles' })),
          get: () => get().effect?.id ?? '',
          set: (v) =>
            A.updateClip(id, (c) => {
              c.effect = { id: v, intensity: c.effect?.intensity ?? 0.7 };
              c.name = EFFECTS.find((e) => e.id === v)?.label ?? c.name;
            }),
        }),
        slider({
          label: 'Intensity',
          min: 0,
          max: 1,
          mul: 100,
          unit: '%',
          get: () => get().effect?.intensity ?? 0.7,
          set: (v) => A.updateClip(id, (c) => (c.effect = { id: c.effect?.id ?? 'shake', intensity: v }), `fx:${id}`),
        }),
        note('Effects apply to everything below them in the frame for as long as the effect clip lasts.'),
      ]),
    );
  }
}
