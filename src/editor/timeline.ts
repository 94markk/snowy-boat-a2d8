// Multi-track timeline: rendering, scrubbing, drag/trim, drops, zoom.
import type { Clip, MediaItem, Track } from './types';
import * as A from './actions';
import { commands, runCommand } from './commands';
import { engine } from './engine';
import { icon } from './icons';
import { clipEnd, maxDuration, trackKindFor } from './model';
import { store } from './store';
import { builtinSound } from './sounds';
import { contextMenu, dnd, openInspectorTab, openLibraryTab, type DragPayload, type MenuItem } from './ui';
import { $, EPS, clamp, h, shortTime, timecode, uid } from './utils';
import { TRACK_LABEL } from './presets';

const HEAD_W = 148;
const ROW_H = { main: 76, video: 60, text: 34, effect: 34, audio: 54 } as const;
const MIN_DUR = 0.1;

const KIND_ICON: Record<string, string> = { video: 'film', text: 'type', effect: 'sparkles', audio: 'music' };

const zoomToSlider = (pps: number) => (Math.log(pps / 4) / Math.log(600 / 4)) * 100;
const sliderToZoom = (v: number) => 4 * Math.pow(600 / 4, v / 100);

export class Timeline {
  private scroll = $('#tl-scroll');
  private content = $('#tl-content');
  private rows = $('#tl-rows');
  private ruler = $<HTMLCanvasElement>('#tl-ruler');
  private playhead = $('#tl-playhead');
  private snapline = $('#tl-snapline');
  private zoom = $<HTMLInputElement>('#tl-zoom');
  private queued = false;
  private dragging = false;

  constructor() {
    store.on((r) => {
      if (r === 'time') this.updatePlayhead(true);
      else if (r === 'play') this.updateToolbar();
      else this.queue();
    });
    this.scroll.addEventListener('scroll', () => this.drawRuler(), { passive: true });
    new ResizeObserver(() => this.queue()).observe(this.scroll);
    this.content.addEventListener('pointerdown', (e) => this.onPointerDown(e));
    this.content.addEventListener('contextmenu', (e) => this.onContextMenu(e));
    this.content.addEventListener('dblclick', (e) => {
      const el = (e.target as HTMLElement).closest<HTMLElement>('.clip');
      if (el && store.clip(el.dataset.clip)?.type === 'text') openInspectorTab('text');
    });
    this.scroll.addEventListener(
      'wheel',
      (e) => {
        if (!(e.ctrlKey || e.metaKey)) return;
        e.preventDefault();
        const rect = this.scroll.getBoundingClientRect();
        const x = e.clientX - rect.left - HEAD_W;
        const tAt = (this.scroll.scrollLeft + x) / store.pps;
        store.setZoom(store.pps * Math.exp(-e.deltaY * 0.0025));
        this.render();
        this.scroll.scrollLeft = tAt * store.pps - x;
      },
      { passive: false },
    );
    this.zoom.addEventListener('input', () => store.setZoom(sliderToZoom(+this.zoom.value)));
    addEventListener('timeline:fit', () => this.fit());
    this.setupDrop();
    $('#timeline .tl-toolbar').addEventListener('click', (e) => {
      const b = (e.target as HTMLElement).closest<HTMLElement>('[data-cmd]');
      if (b) runCommand(b.dataset.cmd!);
    });
    this.render();
  }

  private queue(): void {
    if (this.queued) return;
    this.queued = true;
    requestAnimationFrame(() => {
      this.queued = false;
      if (!this.dragging) this.render();
    });
  }

  private get laneWidth(): number {
    const visible = Math.max(200, this.scroll.clientWidth - HEAD_W);
    return Math.max((store.duration + 20) * store.pps, visible);
  }

  fit(): void {
    const visible = Math.max(200, this.scroll.clientWidth - HEAD_W - 40);
    store.setZoom(visible / Math.max(store.duration, 5));
    this.scroll.scrollLeft = 0;
  }

  // ------------------------------------------------------------ rendering

  render(): void {
    const p = store.project;
    const w = this.laneWidth;
    this.content.style.width = `${HEAD_W + w}px`;
    this.rows.replaceChildren(...p.tracks.map((t, i) => this.renderRow(t, i, w)));
    this.zoom.value = String(zoomToSlider(store.pps));
    this.drawRuler();
    this.updatePlayhead(false);
    this.updateToolbar();
  }

  private updateToolbar(): void {
    for (const b of document.querySelectorAll<HTMLButtonElement>('#timeline [data-cmd]')) {
      const c = commands[b.dataset.cmd!];
      if (!c) continue;
      b.disabled = c.enabled ? !c.enabled() : false;
    }
    $('#timeline [data-cmd="magnet"]').setAttribute('aria-pressed', String(store.project.magnet));
    $('#timeline [data-cmd="snap"]').setAttribute('aria-pressed', String(store.project.snap));
  }

  private trackLabel(t: Track, index: number): string {
    if (t.main) return 'Main track';
    const same = store.project.tracks.filter((x) => x.kind === t.kind && !x.main);
    const n = same.indexOf(t) + 1;
    return `${TRACK_LABEL[t.kind]}${same.length > 1 ? ` ${n}` : ''}` || `Track ${index + 1}`;
  }

  private renderRow(t: Track, index: number, w: number): HTMLElement {
    const hgt = t.main ? ROW_H.main : ROW_H[t.kind];
    const row = h('div', {
      class: `tl-row tl-row--${t.kind}${t.main ? ' is-main' : ''}${t.locked ? ' is-locked' : ''}${t.hidden ? ' is-hidden' : ''}${t.muted ? ' is-muted' : ''}`,
      'data-track': t.id,
      style: `height:${hgt}px`,
    });
    const btn = (prop: 'hidden' | 'muted' | 'locked', on: string, off: string, title: string) =>
      h('button', {
        class: 'tl-head-btn',
        'data-tact': prop,
        'aria-pressed': String(t[prop]),
        title,
        html: icon(t[prop] ? on : off, 15),
      });
    const head = h(
      'div',
      { class: 'tl-head' },
      h('span', { class: 'tl-head-ic', html: icon(KIND_ICON[t.kind], 15) }),
      h('span', { class: 'tl-head-name' }, this.trackLabel(t, index)),
      h(
        'span',
        { class: 'tl-head-btns' },
        t.kind === 'video' || t.kind === 'text' || t.kind === 'effect' ? btn('hidden', 'eyeOff', 'eye', 'Hide track') : null,
        t.kind === 'video' || t.kind === 'audio' ? btn('muted', 'volumeX', 'volume', 'Mute track') : null,
        btn('locked', 'lock', 'unlock', 'Lock track'),
      ),
    );
    const lane = h('div', { class: 'tl-lane', 'data-track': t.id, style: `width:${w}px` });
    const clips = store.clipsOn(t.id);
    for (const c of clips) lane.append(this.renderClip(c, hgt));
    if (t.kind === 'video') {
      for (let i = 1; i < clips.length; i++) {
        const a = clips[i - 1];
        const b = clips[i];
        if (Math.abs(clipEnd(a) - b.start) > 1e-3) continue;
        const sel = store.selection.kind === 'transition' && store.selection.id === b.id;
        if (b.transition) {
          const d = b.transition.duration;
          lane.append(h('div', { class: 'tl-trans-band', style: `left:${(b.start - d / 2) * store.pps}px;width:${d * store.pps}px` }));
        }
        lane.append(
          h('button', {
            class: `tl-trans${b.transition ? ' has' : ''}${sel ? ' is-selected' : ''}`,
            'data-trans': b.id,
            style: `left:${b.start * store.pps}px`,
            title: b.transition ? 'Edit transition' : 'Add transition',
            html: icon(b.transition ? 'transition' : 'plus', 12),
          }),
        );
      }
    }
    if (t.main && !clips.length) {
      lane.append(h('div', { class: 'tl-empty', html: `${icon('upload', 18)}<span>Drag media here, or press <b>+</b> on any item in the library</span>` }));
    }
    row.append(head, lane);
    return row;
  }

  private renderClip(c: Clip, hgt: number): HTMLElement {
    const pps = store.pps;
    const w = Math.max(3, c.duration * pps);
    const selected = store.selection.kind === 'clips' && store.selection.ids.includes(c.id);
    const media = c.mediaId ? store.media.get(c.mediaId) : undefined;
    const el = h('div', {
      class: `clip clip--${c.type}${selected ? ' is-selected' : ''}${c.muted ? ' is-muted' : ''}${!media && c.mediaId ? ' is-missing' : ''}`,
      'data-clip': c.id,
      style: `left:${c.start * pps}px;width:${w}px`,
      title: c.name,
    });
    const body = h('div', { class: 'clip-body' });
    const showWave = media?.waveform && (c.type === 'audio' || (c.type === 'video' && media.hasAudio && !c.muted && hgt >= 60));
    if ((c.type === 'video' || c.type === 'image') && media?.thumbs.length) {
      body.append(this.strip(c, media, w, hgt - 2 - (showWave ? 16 : 0)));
    }
    if (showWave && media) body.append(this.wave(c, media, w, c.type === 'audio' ? hgt - 20 : 14, c.type !== 'audio'));
    let label = c.name;
    if (c.type === 'text') label = c.text?.content.replace(/\n/g, ' ') || 'Text';
    const lab = h(
      'div',
      { class: 'clip-label' },
      c.type === 'text' ? h('span', { class: 'clip-ic', html: icon('type', 12) }) : null,
      c.type === 'effect' ? h('span', { class: 'clip-ic', html: icon('sparkles', 12) }) : null,
      c.type === 'sticker' ? h('span', { class: 'clip-emoji' }, c.emoji ?? '') : null,
      c.type === 'audio' ? h('span', { class: 'clip-ic', html: icon('music', 12) }) : null,
      h('span', { class: 'clip-name' }, label),
      c.type === 'video' || c.type === 'audio' ? h('span', { class: 'clip-dur' }, shortTime(c.duration)) : null,
    );
    const badges = h('div', { class: 'clip-badges' });
    if (Math.abs(c.speed - 1) > 0.001) badges.append(h('span', { class: 'clip-badge' }, `${+c.speed.toFixed(2)}x`));
    if (c.muted) badges.append(h('span', { class: 'clip-badge', html: icon('volumeX', 11) }));
    if (c.filter.id !== 'none') badges.append(h('span', { class: 'clip-badge', html: icon('filter', 11) }));
    if (c.chroma.enabled || c.mask.type !== 'none') badges.append(h('span', { class: 'clip-badge', html: icon('mask', 11) }));
    body.append(lab, badges);
    if (c.fadeIn > 0 && (c.type === 'audio' || c.type === 'video')) body.append(h('span', { class: 'clip-fade in', style: `width:${c.fadeIn * pps}px` }));
    if (c.fadeOut > 0 && (c.type === 'audio' || c.type === 'video')) body.append(h('span', { class: 'clip-fade out', style: `width:${c.fadeOut * pps}px` }));
    for (const k of c.keyframes) body.append(h('span', { class: 'clip-kf', style: `left:${k.t * pps}px` }));
    el.append(body, h('span', { class: 'clip-handle l', 'data-edge': 'l' }), h('span', { class: 'clip-handle r', 'data-edge': 'r' }));
    return el;
  }

  private strip(c: Clip, m: MediaItem, w: number, hgt: number): HTMLElement {
    const strip = h('div', { class: 'clip-strip', style: `height:${hgt}px` });
    const aspect = m.width && m.height ? m.width / m.height : 16 / 9;
    const tileW = Math.max(24, Math.round(hgt * aspect));
    if (c.type === 'image') {
      strip.style.backgroundImage = `url("${m.thumbs[0]}")`;
      strip.style.backgroundSize = `${tileW}px 100%`;
      return strip;
    }
    const n = Math.min(400, Math.ceil(w / tileW));
    for (let i = 0; i < n; i++) {
      const src = c.in + ((i * tileW + tileW / 2) / store.pps) * c.speed;
      const idx = clamp(Math.floor(src / m.thumbInterval), 0, m.thumbs.length - 1);
      strip.append(h('img', { src: m.thumbs[idx], alt: '', draggable: 'false', style: `width:${tileW}px` }));
    }
    return strip;
  }

  private wave(c: Clip, m: MediaItem, w: number, hgt: number, mini: boolean): HTMLCanvasElement {
    const cw = Math.max(1, Math.min(Math.round(w), 16000));
    const cv = h('canvas', { class: `clip-wave${mini ? ' mini' : ''}`, width: cw, height: Math.max(4, hgt) });
    cv.style.width = `${w}px`;
    cv.style.height = `${hgt}px`;
    const ctx = cv.getContext('2d')!;
    const peaks = m.waveform!;
    ctx.fillStyle = mini ? 'rgba(160,220,255,.75)' : 'rgba(190,255,220,.85)';
    const vol = Math.min(1.4, c.volume) * (c.muted ? 0.2 : 1);
    for (let x = 0; x < cw; x += 2) {
      const src = c.in + ((x / cw) * w * c.speed) / store.pps;
      const v = peaks[Math.floor(src * m.waveRate)] ?? 0;
      const amp = Math.max(1, v * vol * hgt * (mini ? 1 : 0.95));
      if (mini) ctx.fillRect(x, hgt - amp, 1.4, amp);
      else ctx.fillRect(x, (hgt - amp) / 2, 1.4, amp);
    }
    return cv;
  }

  private drawRuler(): void {
    const cv = this.ruler;
    const dpr = Math.min(2, devicePixelRatio || 1);
    const cssW = Math.max(1, this.scroll.clientWidth - HEAD_W);
    const cssH = 26;
    if (cv.width !== Math.round(cssW * dpr)) {
      cv.width = Math.round(cssW * dpr);
      cv.height = cssH * dpr;
      cv.style.width = `${cssW}px`;
      cv.style.height = `${cssH}px`;
    }
    const ctx = cv.getContext('2d')!;
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, cssW, cssH);
    const pps = store.pps;
    const sl = this.scroll.scrollLeft;
    const steps = [1 / 30, 0.1, 0.2, 0.5, 1, 2, 5, 10, 15, 30, 60, 120, 300, 600];
    const major = steps.find((s) => s * pps >= 90) ?? 600;
    const minor = major / (major <= 0.2 ? 2 : 5);
    const perMajor = Math.round(major / minor);
    const i0 = Math.floor(sl / pps / minor);
    const i1 = Math.ceil((sl + cssW) / pps / minor) + 1;
    ctx.font = '10px Inter, system-ui, sans-serif';
    ctx.textBaseline = 'top';
    for (let i = i0; i <= i1; i++) {
      const t = Math.round(i * minor * 1000) / 1000;
      const x = Math.round(t * pps - sl) + 0.5;
      const isMajor = i % perMajor === 0;
      ctx.fillStyle = isMajor ? 'rgba(255,255,255,.45)' : 'rgba(255,255,255,.18)';
      ctx.fillRect(x, isMajor ? 14 : 19, 1, isMajor ? 12 : 7);
      if (isMajor) {
        ctx.fillStyle = 'rgba(255,255,255,.55)';
        ctx.fillText(major < 1 ? timecode(t, store.project.fps) : shortTime(t), x + 3, 3);
      }
    }
    for (const m of store.project.markers) {
      const x = m.t * pps - sl;
      if (x < -10 || x > cssW + 10) continue;
      ctx.fillStyle = m.color;
      ctx.beginPath();
      ctx.moveTo(x - 5, 14);
      ctx.lineTo(x + 5, 14);
      ctx.lineTo(x + 5, 21);
      ctx.lineTo(x, 26);
      ctx.lineTo(x - 5, 21);
      ctx.closePath();
      ctx.fill();
    }
  }

  private updatePlayhead(follow: boolean): void {
    const x = store.time * store.pps;
    this.playhead.style.transform = `translateX(${HEAD_W + x}px)`;
    if (follow && store.playing && !this.dragging) {
      const visible = this.scroll.clientWidth - HEAD_W;
      const sl = this.scroll.scrollLeft;
      if (x > sl + visible - 30) this.scroll.scrollLeft = x - 40;
      else if (x < sl) this.scroll.scrollLeft = Math.max(0, x - 40);
    }
  }

  // ------------------------------------------------------------ interaction

  private timeAt(clientX: number): number {
    const r = this.content.getBoundingClientRect();
    return Math.max(0, (clientX - r.left - HEAD_W) / store.pps);
  }

  private onPointerDown(e: PointerEvent): void {
    if (e.button !== 0) return;
    const target = e.target as HTMLElement;
    const headBtn = target.closest<HTMLElement>('[data-tact]');
    if (headBtn) {
      const tid = headBtn.closest<HTMLElement>('.tl-row')!.dataset.track!;
      const prop = headBtn.dataset.tact as 'hidden' | 'muted' | 'locked';
      store.commit((p) => {
        const t = p.tracks.find((x) => x.id === tid);
        if (t) t[prop] = !t[prop];
      });
      return;
    }
    if (target.closest('.tl-head')) return;
    const trans = target.closest<HTMLElement>('.tl-trans');
    if (trans) {
      const c = store.clip(trans.dataset.trans);
      if (!c) return;
      if (!c.transition) A.setTransition(c.id, 'fade', 0.6);
      store.select({ kind: 'transition', id: c.id });
      openLibraryTab('transitions');
      return;
    }
    const clipEl = target.closest<HTMLElement>('.clip');
    if (clipEl) {
      const c = store.clip(clipEl.dataset.clip)!;
      if (e.shiftKey || e.ctrlKey || e.metaKey) {
        store.selectClip(c.id, true);
        return;
      }
      const already = store.selection.kind === 'clips' && store.selection.ids.includes(c.id);
      if (!already || store.selectedClips.length > 1) store.selectClip(c.id);
      if (store.track(c.trackId)?.locked) return;
      const handle = target.closest<HTMLElement>('.clip-handle');
      if (handle) this.startTrim(e, c, clipEl, handle.dataset.edge as 'l' | 'r');
      else this.startMove(e, c, clipEl);
      return;
    }
    if (target.closest('.tl-lane') || target.closest('.tl-rows')) store.select({ kind: 'none' });
    this.startScrub(e);
  }

  private startScrub(e: PointerEvent): void {
    engine.pause();
    const move = (ev: PointerEvent) => engine.seek(this.snapTime(this.timeAt(ev.clientX)));
    move(e);
    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  }

  private snapTime(t: number): number {
    if (!store.project.snap) return t;
    const pts = this.snapPoints('');
    const thr = 6 / store.pps;
    let best = t;
    let bestD = thr;
    for (const p of pts) {
      const d = Math.abs(p - t);
      if (d < bestD) {
        bestD = d;
        best = p;
      }
    }
    return best;
  }

  private snapPoints(excludeId: string, includePlayhead = false): number[] {
    const pts = [0, ...store.project.markers.map((m) => m.t)];
    if (includePlayhead) pts.push(store.time);
    for (const c of store.project.clips) {
      if (c.id === excludeId) continue;
      pts.push(c.start, clipEnd(c));
    }
    return pts;
  }

  /** Returns a shift that snaps any of `edges` to a snap point, or null. */
  private snap(edges: number[], pts: number[]): { delta: number; at: number } | null {
    if (!store.project.snap) return null;
    const thr = 8 / store.pps;
    let best: { delta: number; at: number } | null = null;
    for (const e of edges) {
      for (const p of pts) {
        const d = p - e;
        if (Math.abs(d) < thr && (!best || Math.abs(d) < Math.abs(best.delta))) best = { delta: d, at: p };
      }
    }
    return best;
  }

  private showSnap(at: number | null): void {
    this.snapline.hidden = at === null;
    if (at !== null) this.snapline.style.transform = `translateX(${HEAD_W + at * store.pps}px)`;
  }

  private autoScroll(clientX: number): void {
    const r = this.scroll.getBoundingClientRect();
    if (clientX > r.right - 40) this.scroll.scrollLeft += 18;
    else if (clientX < r.left + HEAD_W + 30) this.scroll.scrollLeft -= 18;
  }

  private startMove(e: PointerEvent, c: Clip, el: HTMLElement): void {
    const startX = e.clientX;
    const startY = e.clientY;
    const startScroll = this.scroll.scrollLeft;
    const pps = store.pps;
    const kind = trackKindFor(c.type);
    const pts = this.snapPoints(c.id, true);
    let moved = false;
    let ns = c.start;
    let tid = c.trackId;
    let newTrack: 'above' | 'below' | null = null;
    const move = (ev: PointerEvent) => {
      const dx = ev.clientX - startX + (this.scroll.scrollLeft - startScroll);
      if (!moved && Math.hypot(ev.clientX - startX, ev.clientY - startY) < 4) return;
      if (!moved) {
        moved = true;
        this.dragging = true;
        engine.pause();
        el.classList.add('is-dragging');
      }
      ns = Math.max(0, c.start + dx / pps);
      const s = this.snap([ns, ns + c.duration], pts);
      if (s) ns = Math.max(0, ns + s.delta);
      this.showSnap(s ? s.at : null);
      el.style.left = `${ns * pps}px`;
      // Which lane is under the pointer?
      const under = document.elementsFromPoint(ev.clientX, ev.clientY);
      const lane = under.find((x) => x.classList.contains('tl-lane')) as HTMLElement | undefined;
      const laneTrack = lane ? store.track(lane.dataset.track) : undefined;
      newTrack = null;
      document.querySelectorAll('.tl-row.is-newtrack-above,.tl-row.is-newtrack-below').forEach((r) => r.classList.remove('is-newtrack-above', 'is-newtrack-below'));
      if (laneTrack && laneTrack.kind === kind) {
        tid = laneTrack.id;
        if (lane !== el.parentElement) lane!.append(el);
      } else {
        // Hovering a different kind of track (or empty space): create a new track
        const rowsRect = this.rows.getBoundingClientRect();
        const sameRows = [...this.rows.querySelectorAll<HTMLElement>('.tl-row')].filter((r) => store.track(r.dataset.track)?.kind === kind);
        if (sameRows.length) {
          const first = sameRows[0].getBoundingClientRect();
          const last = sameRows[sameRows.length - 1].getBoundingClientRect();
          if (ev.clientY < first.top || ev.clientY < rowsRect.top) {
            newTrack = 'above';
            sameRows[0].classList.add('is-newtrack-above');
          } else if (ev.clientY > last.bottom) {
            newTrack = 'below';
            sameRows[sameRows.length - 1].classList.add('is-newtrack-below');
          }
        }
      }
      this.autoScroll(ev.clientX);
    };
    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      document.querySelectorAll('.tl-row.is-newtrack-above,.tl-row.is-newtrack-below').forEach((r) => r.classList.remove('is-newtrack-above', 'is-newtrack-below'));
      this.showSnap(null);
      this.dragging = false;
      if (!moved) return;
      if (newTrack) {
        const edgeTrack = newTrack === 'above'
          ? store.project.tracks.find((t) => t.kind === kind)
          : [...store.project.tracks].reverse().find((t) => t.kind === kind);
        store.commit((p) => {
          const cl = p.clips.find((x) => x.id === c.id)!;
          const t = { id: uid('t_'), kind, muted: false, hidden: false, locked: false };
          const idx = edgeTrack ? p.tracks.findIndex((x) => x.id === edgeTrack.id) : 0;
          p.tracks.splice(newTrack === 'above' ? idx : idx + 1, 0, t);
          cl.trackId = t.id;
          cl.start = ns;
        }, '', [c.id]);
      } else {
        A.moveClip(c.id, tid, ns);
      }
      this.render();
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  }

  private startTrim(e: PointerEvent, c: Clip, el: HTMLElement, edge: 'l' | 'r'): void {
    const startX = e.clientX;
    const pps = store.pps;
    const media = c.mediaId ? store.media.get(c.mediaId) : undefined;
    const p = store.project;
    const track = store.track(c.trackId)!;
    const free = !(track.main && p.magnet);
    const siblings = store.clipsOn(c.trackId).filter((x) => x.id !== c.id);
    const prevEnd = free ? Math.max(0, ...siblings.filter((x) => clipEnd(x) <= c.start + EPS).map(clipEnd)) : 0;
    const nextStart = free ? Math.min(Infinity, ...siblings.filter((x) => x.start >= clipEnd(c) - EPS).map((x) => x.start)) : Infinity;
    const pts = this.snapPoints(c.id, true);
    const hasSrc = c.type === 'video' || c.type === 'audio';
    let ns = c.start;
    let nd = c.duration;
    let nin = c.in;
    this.dragging = true;
    engine.pause();
    el.classList.add('is-trimming');
    const move = (ev: PointerEvent) => {
      const d = (ev.clientX - startX) / pps;
      if (edge === 'r') {
        const maxD = Math.min(maxDuration(c, media), nextStart - c.start);
        let end = c.start + clamp(c.duration + d, MIN_DUR, maxD);
        const s = this.snap([end], pts);
        if (s) end += s.delta;
        nd = clamp(end - c.start, MIN_DUR, maxD);
        this.showSnap(s ? s.at : null);
        el.style.width = `${nd * pps}px`;
      } else {
        const minStart = Math.max(prevEnd, hasSrc ? c.start - c.in / c.speed : 0, 0);
        const maxStart = clipEnd(c) - MIN_DUR;
        let s0 = clamp(c.start + d, minStart, maxStart);
        const s = this.snap([s0], pts);
        if (s) s0 = clamp(s0 + s.delta, minStart, maxStart);
        this.showSnap(s ? s.at : null);
        ns = s0;
        nd = clipEnd(c) - ns;
        nin = hasSrc ? c.in + (ns - c.start) * c.speed : c.in;
        el.style.left = `${ns * pps}px`;
        el.style.width = `${nd * pps}px`;
      }
    };
    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      this.showSnap(null);
      this.dragging = false;
      el.classList.remove('is-trimming');
      if (Math.abs(nd - c.duration) > 1e-4 || Math.abs(ns - c.start) > 1e-4) A.trimClip(c.id, edge, ns, nd, nin);
      store.seal();
      this.render();
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  }

  private onContextMenu(e: MouseEvent): void {
    const clipEl = (e.target as HTMLElement).closest<HTMLElement>('.clip');
    if (!clipEl) return;
    e.preventDefault();
    const c = store.clip(clipEl.dataset.clip)!;
    if (!(store.selection.kind === 'clips' && store.selection.ids.includes(c.id))) store.selectClip(c.id);
    const item = (name: string, label?: string): MenuItem => {
      const cmd = commands[name];
      return { label: label ?? cmd.label, icon: cmd.icon, hint: cmd.keys, disabled: cmd.enabled ? !cmd.enabled() : false, run: () => runCommand(name) };
    };
    const items: (MenuItem | 'sep')[] = [item('split'), item('duplicate'), item('copy'), item('paste'), 'sep'];
    if (c.type === 'video') items.push(item('freeze'), item('detach'));
    if (c.type === 'video' || c.type === 'audio') items.push(item('muteClip', c.muted ? 'Unmute clip' : 'Mute clip'));
    if (c.type === 'video' || c.type === 'audio') items.push({ label: 'Speed…', icon: 'speed', run: () => openInspectorTab('speed') });
    if (c.type === 'video' || c.type === 'image') items.push({ label: 'Adjust colour…', icon: 'sliders', run: () => openInspectorTab('adjust') });
    items.push('sep', { ...item('delete'), danger: true });
    contextMenu(e.clientX, e.clientY, items);
  }

  // ------------------------------------------------------------ library drops

  private setupDrop(): void {
    const clear = () => document.querySelectorAll('.tl-lane.is-drop').forEach((x) => x.classList.remove('is-drop'));
    this.scroll.addEventListener('dragover', (e) => {
      const p = dnd.get();
      if (!p) return;
      e.preventDefault();
      if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy';
      clear();
      const lane = (e.target as HTMLElement).closest<HTMLElement>('.tl-lane');
      if (lane) lane.classList.add('is-drop');
    });
    this.scroll.addEventListener('dragleave', (e) => {
      if (!this.scroll.contains(e.relatedTarget as Node)) clear();
    });
    this.scroll.addEventListener('drop', (e) => {
      const p = dnd.get();
      clear();
      if (!p) return;
      e.preventDefault();
      dnd.set(null);
      const target = e.target as HTMLElement;
      const lane = target.closest<HTMLElement>('.tl-lane');
      const t = this.timeAt(e.clientX);
      void this.drop(p, lane?.dataset.track, t, target);
    });
  }

  private async drop(p: DragPayload, trackId: string | undefined, t: number, target: HTMLElement): Promise<void> {
    const track = store.track(trackId);
    const compatible = (kind: string) => (track && track.kind === kind ? track.id : undefined);
    switch (p.type) {
      case 'media': {
        const m = store.media.get(p.id);
        if (!m) return;
        const kind = m.kind === 'audio' ? 'audio' : 'video';
        A.addMedia(m, { trackId: compatible(kind), time: t });
        break;
      }
      case 'sound': {
        const m = await builtinSound(p.id);
        if (m) A.addMedia(m, { trackId: compatible('audio'), time: t });
        break;
      }
      case 'text':
        A.addText(p.preset, { trackId: compatible('text'), time: t });
        break;
      case 'sticker':
        A.addSticker(p.emoji, { trackId: compatible('text'), time: t });
        break;
      case 'effect':
        A.addEffect(p.id, { trackId: compatible('effect'), time: t });
        break;
      case 'transition': {
        const trans = target.closest<HTMLElement>('.tl-trans');
        const clipEl = target.closest<HTMLElement>('.clip');
        let id = trans?.dataset.trans;
        if (!id && clipEl) {
          const c = store.clip(clipEl.dataset.clip);
          if (c && store.project.clips.some((o) => o.trackId === c.trackId && Math.abs(clipEnd(o) - c.start) < 1e-3)) id = c.id;
        }
        if (!id) {
          // nearest cut on the track under the cursor
          const list = track ? store.clipsOn(track.id) : [];
          let best: Clip | undefined;
          for (let i = 1; i < list.length; i++) {
            if (Math.abs(clipEnd(list[i - 1]) - list[i].start) > 1e-3) continue;
            if (!best || Math.abs(list[i].start - t) < Math.abs(best.start - t)) best = list[i];
          }
          id = best?.id;
        }
        if (id) {
          A.setTransition(id, p.id);
          store.select({ kind: 'transition', id });
        }
        break;
      }
    }
  }
}
