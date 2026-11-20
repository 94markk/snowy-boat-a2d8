// Player: canvas sizing, transport UI, on-canvas selection & direct manipulation.
import type { Clip } from './types';
import * as A from './actions';
import { runCommand } from './commands';
import { engine } from './engine';
import { icon } from './icons';
import { clipEnd, getTransformAt, isVisual, setTransformValue } from './model';
import { store } from './store';
import { openInspectorTab } from './ui';
import { $, clamp, timecode } from './utils';

export class Preview {
  private stage = $('#preview-stage');
  private wrap = $('#canvas-wrap');
  private canvas = $<HTMLCanvasElement>('#preview-canvas');
  private box = $('#sel-box');
  private guideV = $('#guide-v');
  private guideH = $('#guide-h');
  private timeCur = $('#time-current');
  private timeTotal = $('#time-total');
  private playBtn = $('#btn-play');
  private loopBtn = $('#btn-loop');
  private scale = 1; // css px per project px

  constructor() {
    engine.attach(this.canvas, $('#media-pool'));
    new ResizeObserver(() => this.layout()).observe(this.stage);
    store.on((r) => {
      if (r === 'project' || r === 'load') this.layout();
      if (r === 'play') this.updateTransport();
      if (r === 'time' || r === 'project' || r === 'load') this.updateTime();
      if (r === 'selection' || r === 'project') this.updateBox();
    });
    engine.onFrame(() => this.updateBox());
    $('#player').addEventListener('click', (e) => {
      const b = (e.target as HTMLElement).closest<HTMLElement>('[data-cmd]');
      if (b) runCommand(b.dataset.cmd!);
    });
    $('#btn-fullscreen').addEventListener('click', () => {
      if (document.fullscreenElement) void document.exitFullscreen();
      else void this.stage.requestFullscreen?.();
    });
    this.wrap.addEventListener('pointerdown', (e) => this.onPointerDown(e));
    this.stage.addEventListener('pointerdown', (e) => {
      if (e.target === this.stage && !engine.exporting) store.select({ kind: 'none' });
    });
    this.wrap.addEventListener('dblclick', () => {
      if (store.selectedClip?.type === 'text') openInspectorTab('text');
    });
    this.layout();
    this.updateTransport();
    this.updateTime();
  }

  private layout(): void {
    const p = store.project;
    const r = this.stage.getBoundingClientRect();
    const pad = 30;
    const aw = Math.max(50, r.width - pad * 2);
    const ah = Math.max(50, r.height - pad * 2);
    const s = Math.min(aw / p.width, ah / p.height);
    const cw = Math.floor(p.width * s);
    const ch = Math.floor(p.height * s);
    this.scale = cw / p.width;
    this.wrap.style.width = `${cw}px`;
    this.wrap.style.height = `${ch}px`;
    const dpr = Math.min(2, devicePixelRatio || 1);
    const pw = Math.round(cw * dpr);
    const ph = Math.round(ch * dpr);
    if (this.canvas.width !== pw || this.canvas.height !== ph) {
      this.canvas.width = pw;
      this.canvas.height = ph;
    }
    this.canvas.style.width = `${cw}px`;
    this.canvas.style.height = `${ch}px`;
    engine.invalidate();
    this.updateBox();
  }

  private updateTransport(): void {
    this.playBtn.innerHTML = icon(store.playing ? 'pause' : 'play', 22);
    this.playBtn.setAttribute('aria-label', store.playing ? 'Pause' : 'Play');
    this.loopBtn.setAttribute('aria-pressed', String(engine.loop));
    document.body.classList.toggle('is-playing', store.playing);
  }

  private updateTime(): void {
    const fps = store.project.fps;
    this.timeCur.textContent = timecode(store.time, fps);
    this.timeTotal.textContent = timecode(store.duration, fps);
  }

  // ------------------------------------------------------------ selection box

  private activeSelected(): Clip | undefined {
    const c = store.selectedClip;
    if (!c || !isVisual(c)) return undefined;
    if (store.time < c.start || store.time >= clipEnd(c)) return undefined;
    if (store.track(c.trackId)?.hidden) return undefined;
    return c;
  }

  private updateBox(): void {
    const c = this.activeSelected();
    const g = c ? engine.renderer.geometry(c, store.time) : null;
    if (!c || !g || engine.exporting) {
      this.box.hidden = true;
      return;
    }
    const s = this.scale;
    this.box.hidden = false;
    this.box.style.width = `${g.w * s}px`;
    this.box.style.height = `${g.h * s}px`;
    this.box.style.transform = `translate(${g.cx * s - (g.w * s) / 2}px, ${g.cy * s - (g.h * s) / 2}px) rotate(${g.rot}deg)`;
    this.box.classList.toggle('is-locked', !!store.track(c.trackId)?.locked);
  }

  // ------------------------------------------------------------ interaction

  private toProject(e: PointerEvent): { x: number; y: number } {
    const r = this.wrap.getBoundingClientRect();
    return { x: (e.clientX - r.left) / this.scale, y: (e.clientY - r.top) / this.scale };
  }

  private hit(pt: { x: number; y: number }): Clip | undefined {
    for (const c of A.visualClipsAt(store.time)) {
      const g = engine.renderer.geometry(c, store.time);
      if (!g) continue;
      const a = (-g.rot * Math.PI) / 180;
      const dx = pt.x - g.cx;
      const dy = pt.y - g.cy;
      const lx = dx * Math.cos(a) - dy * Math.sin(a);
      const ly = dx * Math.sin(a) + dy * Math.cos(a);
      if (Math.abs(lx) <= g.w / 2 && Math.abs(ly) <= g.h / 2) return c;
    }
    return undefined;
  }

  private onPointerDown(e: PointerEvent): void {
    if (e.button !== 0 || engine.exporting) return;
    const handle = (e.target as HTMLElement).closest<HTMLElement>('[data-h]');
    const pt = this.toProject(e);
    let c = this.activeSelected();
    if (!handle) {
      const hitClip = this.hit(pt);
      if (!hitClip) {
        store.select({ kind: 'none' });
        return;
      }
      if (hitClip.id !== c?.id) store.selectClip(hitClip.id);
      c = hitClip;
    }
    if (!c || store.track(c.trackId)?.locked) return;
    engine.pause();
    e.preventDefault();
    const id = c.id;
    const local = store.time - c.start;
    const t0 = { ...getTransformAt(c, local) };
    const g = engine.renderer.geometry(c, store.time)!;
    const mode = handle?.dataset.h ?? 'move';
    const startAngle = Math.atan2(pt.y - g.cy, pt.x - g.cx);
    const startDist = Math.hypot(pt.x - g.cx, pt.y - g.cy) || 1;
    const W = store.project.width;
    const H = store.project.height;
    const move = (ev: PointerEvent) => {
      const q = this.toProject(ev);
      if (mode === 'move') {
        let x = t0.x + (q.x - pt.x);
        let y = t0.y + (q.y - pt.y);
        const thr = 12 / this.scale;
        const snapX = Math.abs(x) < thr;
        const snapY = Math.abs(y) < thr;
        if (snapX) x = 0;
        if (snapY) y = 0;
        this.guideV.hidden = !snapX;
        this.guideH.hidden = !snapY;
        x = clamp(x, -W * 1.5, W * 1.5);
        y = clamp(y, -H * 1.5, H * 1.5);
        A.updateClip(id, (cl) => {
          const l = store.time - cl.start;
          setTransformValue(cl, 'x', x, l);
          setTransformValue(cl, 'y', y, l);
        }, `drag:${id}`);
      } else if (mode === 'scale') {
        const d = Math.hypot(q.x - g.cx, q.y - g.cy);
        const s = clamp((t0.scale * d) / startDist, 0.02, 20);
        A.updateClip(id, (cl) => setTransformValue(cl, 'scale', s, store.time - cl.start), `drag:${id}`);
      } else if (mode === 'rotate') {
        const ang = Math.atan2(q.y - g.cy, q.x - g.cx);
        let r = t0.rotation + ((ang - startAngle) * 180) / Math.PI;
        for (const snap of [-180, -90, 0, 90, 180]) if (Math.abs(r - snap) < 4) r = snap;
        A.updateClip(id, (cl) => setTransformValue(cl, 'rotation', Math.round(r * 10) / 10, store.time - cl.start), `drag:${id}`);
      }
    };
    const up = () => {
      removeEventListener('pointermove', move);
      removeEventListener('pointerup', up);
      this.guideV.hidden = true;
      this.guideH.hidden = true;
      store.seal();
    };
    addEventListener('pointermove', move);
    addEventListener('pointerup', up);
  }
}

