// Playback engine: master clock, per-clip media elements, Web Audio graph,
// preview rendering loop and real-time export.
import type { Clip } from './types';
import { Renderer, type SourceProvider } from './renderer';
import { clipEnd, hasSourceTime } from './model';
import { store } from './store';
import { clamp } from './utils';

type MediaEl = HTMLVideoElement | HTMLAudioElement;

interface ElEntry {
  el: MediaEl;
  mediaId: string;
  node?: MediaElementAudioSourceNode;
  gain?: GainNode;
  lastSeek: number;
}

export interface ExportOptions {
  width: number;
  height: number;
  fps: number;
  mimeType: string;
  videoBitsPerSecond: number;
  audioOnly: boolean;
}

interface ExportJob {
  canvas: HTMLCanvasElement;
  recorder: MediaRecorder;
  stream: MediaStream;
  chunks: Blob[];
  end: number;
  mimeType: string;
  onProgress: (p: number) => void;
  resolve: (b: Blob) => void;
  reject: (e: Error) => void;
}

class Engine implements SourceProvider {
  playing = false;
  loop = false;
  canvas: HTMLCanvasElement | null = null;
  readonly renderer: Renderer;
  audioCtx: AudioContext | null = null;
  exportDest: MediaStreamAudioDestinationNode | null = null;

  private startPerf = 0;
  private startT = 0;
  private els = new Map<string, ElEntry>();
  private imgs = new Map<string, HTMLImageElement>();
  private lastFrames = new Map<string, HTMLCanvasElement>();
  private master: GainNode | null = null;
  private speakers: GainNode | null = null;
  private dirty = true;
  private frameListeners: ((t: number) => void)[] = [];
  private job: ExportJob | null = null;
  private pool: HTMLElement | null = null;

  constructor() {
    this.renderer = new Renderer(this);
  }

  attach(canvas: HTMLCanvasElement, pool: HTMLElement): void {
    this.canvas = canvas;
    this.pool = pool;
    store.on((r) => {
      if (r === 'load') this.reset();
      if (r === 'project' || r === 'time' || r === 'load' || r === 'media') this.invalidate();
    });
    document.fonts?.addEventListener?.('loadingdone', () => {
      this.renderer.clearTextCache();
      this.invalidate();
    });
    requestAnimationFrame(this.tick);
  }

  onFrame(fn: (t: number) => void): void {
    this.frameListeners.push(fn);
  }

  invalidate(): void {
    this.dirty = true;
  }

  get exporting(): boolean {
    return !!this.job;
  }

  // ------------------------------------------------------------ sources

  frame(clip: Clip): CanvasImageSource | null {
    if (clip.type === 'video') {
      const e = this.els.get(clip.id) ?? this.ensureEl(clip);
      const v = e?.el as HTMLVideoElement | undefined;
      const ready = !!v && v.readyState >= 2 && v.videoWidth > 0;
      if (ready && !v.seeking) {
        // Keep a copy of the last good frame while paused so scrubbing never flashes black.
        if (!this.playing) this.cacheFrame(clip.id, v);
        return v;
      }
      return this.lastFrames.get(clip.id) ?? (ready ? v! : null);
    }
    if (clip.type === 'image') {
      const img = this.img(clip.mediaId);
      return img && img.complete && img.naturalWidth ? img : null;
    }
    return null;
  }

  dims(clip: Clip): { w: number; h: number } | null {
    if (clip.type === 'video') {
      const v = this.els.get(clip.id)?.el as HTMLVideoElement | undefined;
      if (v?.videoWidth) return { w: v.videoWidth, h: v.videoHeight };
    } else if (clip.type === 'image') {
      const img = this.img(clip.mediaId);
      if (img?.naturalWidth) return { w: img.naturalWidth, h: img.naturalHeight };
    }
    const m = clip.mediaId ? store.media.get(clip.mediaId) : undefined;
    return m && m.width ? { w: m.width, h: m.height } : null;
  }

  private img(mediaId: string | undefined): HTMLImageElement | null {
    if (!mediaId) return null;
    let img = this.imgs.get(mediaId);
    if (!img) {
      const m = store.media.get(mediaId);
      if (!m) return null;
      img = new Image();
      img.decoding = 'async';
      img.onload = () => this.invalidate();
      img.src = m.url;
      this.imgs.set(mediaId, img);
    }
    return img;
  }

  private cacheFrame(id: string, v: HTMLVideoElement): void {
    let c = this.lastFrames.get(id);
    if (!c) {
      c = document.createElement('canvas');
      this.lastFrames.set(id, c);
    }
    if (c.width !== v.videoWidth || c.height !== v.videoHeight) {
      c.width = v.videoWidth;
      c.height = v.videoHeight;
    }
    c.getContext('2d')!.drawImage(v, 0, 0);
  }

  private ensureEl(clip: Clip): ElEntry | null {
    if (!clip.mediaId) return null;
    let e = this.els.get(clip.id);
    if (e && e.mediaId !== clip.mediaId) {
      this.destroy(clip.id);
      e = undefined;
    }
    if (e) return e;
    const media = store.media.get(clip.mediaId);
    if (!media) return null;
    const el = clip.type === 'video' ? document.createElement('video') : document.createElement('audio');
    el.preload = 'auto';
    if (el instanceof HTMLVideoElement) el.playsInline = true;
    el.src = media.url;
    el.addEventListener('seeked', () => this.invalidate());
    el.addEventListener('loadeddata', () => this.invalidate());
    this.pool?.append(el);
    e = { el, mediaId: clip.mediaId, lastSeek: -1 };
    this.els.set(clip.id, e);
    if (this.audioCtx) this.connect(e);
    return e;
  }

  private connect(e: ElEntry): void {
    if (!this.audioCtx || !this.master || e.node) return;
    try {
      e.node = this.audioCtx.createMediaElementSource(e.el);
      e.gain = this.audioCtx.createGain();
      e.gain.gain.value = 0;
      e.node.connect(e.gain).connect(this.master);
    } catch {
      /* element already connected */
    }
  }

  private destroy(id: string): void {
    const e = this.els.get(id);
    if (!e) return;
    e.el.pause();
    e.node?.disconnect();
    e.gain?.disconnect();
    e.el.removeAttribute('src');
    e.el.load();
    e.el.remove();
    this.els.delete(id);
    this.lastFrames.delete(id);
  }

  reset(): void {
    this.pause();
    for (const id of [...this.els.keys()]) this.destroy(id);
    this.imgs.clear();
    this.renderer.clearTextCache();
  }

  ensureAudio(): AudioContext {
    if (!this.audioCtx) {
      const ctx = new AudioContext();
      this.master = ctx.createGain();
      this.speakers = ctx.createGain();
      this.master.connect(this.speakers).connect(ctx.destination);
      this.exportDest = ctx.createMediaStreamDestination();
      this.master.connect(this.exportDest);
      this.audioCtx = ctx;
      for (const e of this.els.values()) this.connect(e);
    }
    if (this.audioCtx.state === 'suspended') void this.audioCtx.resume();
    return this.audioCtx;
  }

  setSpeakersMuted(muted: boolean): void {
    if (this.speakers) this.speakers.gain.value = muted ? 0 : 1;
  }

  // ------------------------------------------------------------ transport

  play(): void {
    if (this.playing) return;
    if (!store.project.clips.length) return;
    this.ensureAudio();
    if (store.time >= store.duration - 0.02) store.setTime(0);
    this.startT = store.time;
    this.startPerf = performance.now();
    this.playing = true;
    store.playing = true;
    store.emit('play');
  }

  pause(): void {
    if (!this.playing) return;
    this.playing = false;
    store.playing = false;
    for (const e of this.els.values()) if (!e.el.paused) e.el.pause();
    store.emit('play');
    this.invalidate();
  }

  toggle(): void {
    if (this.playing) this.pause();
    else this.play();
  }

  seek(t: number): void {
    store.setTime(t);
    if (this.playing) {
      this.startT = store.time;
      this.startPerf = performance.now();
    }
    this.invalidate();
  }

  step(frames: number): void {
    this.pause();
    const fps = store.project.fps;
    this.seek(Math.round(store.time * fps + frames) / fps);
  }

  // ------------------------------------------------------------ loop

  private tick = (): void => {
    requestAnimationFrame(this.tick);
    if (this.playing) {
      const now = performance.now();
      let t = this.startT + (now - this.startPerf) / 1000;
      const end = this.job ? this.job.end : store.duration;
      if (t >= end) {
        if (this.job) {
          t = end;
          this.renderOut(t);
          this.finishExport();
          return;
        }
        if (this.loop && end > 0) {
          t = 0;
          this.startT = 0;
          this.startPerf = now;
        } else {
          t = end;
          this.pause();
        }
      }
      store.time = t;
      store.emit('time');
      this.job?.onProgress(end > 0 ? t / end : 1);
    }
    const t = store.time;
    this.sync(t);
    if (this.playing || this.dirty) {
      this.dirty = false;
      this.renderOut(t);
    }
  };

  private renderOut(t: number): void {
    const rt = Math.min(t, Math.max(0, store.duration - 0.001));
    if (this.job) {
      this.renderer.render(this.job.canvas, rt);
      if (this.canvas) {
        const ctx = this.canvas.getContext('2d')!;
        ctx.drawImage(this.job.canvas, 0, 0, this.canvas.width, this.canvas.height);
      }
    } else if (this.canvas) {
      this.renderer.render(this.canvas, rt);
    }
    for (const f of this.frameListeners) f(t);
  }

  /** Keep every media element aligned with the timeline clock. */
  private sync(t: number): void {
    const p = store.project;
    const seen = new Set<string>();
    const byTrack = new Map<string, Clip[]>();
    for (const c of p.clips) {
      if (!byTrack.has(c.trackId)) byTrack.set(c.trackId, []);
      byTrack.get(c.trackId)!.push(c);
    }
    for (const list of byTrack.values()) list.sort((a, b) => a.start - b.start);
    const ac = this.audioCtx;
    for (const c of p.clips) {
      if (!hasSourceTime(c)) continue;
      seen.add(c.id);
      const track = store.track(c.trackId);
      const list = byTrack.get(c.trackId)!;
      const idx = list.indexOf(c);
      const next = list[idx + 1];
      const pre = c.transition ? c.transition.duration / 2 : 0;
      const post = next?.transition && Math.abs(next.start - clipEnd(c)) < 1e-3 ? next.transition.duration / 2 : 0;
      const near = t >= c.start - 1.5 - pre && t <= clipEnd(c) + post + 0.1;
      let e = this.els.get(c.id);
      if (!near) {
        if (e && !e.el.paused) e.el.pause();
        if (e?.gain && ac) e.gain.gain.setTargetAtTime(0, ac.currentTime, 0.01);
        continue;
      }
      e = this.ensureEl(c) ?? undefined;
      if (!e) continue;
      const el = e.el;
      const local = clamp(t - c.start, 0, Math.max(0, c.duration - 0.001));
      const target = c.in + local * c.speed;
      const inPlay = t >= c.start && t < clipEnd(c);
      if (this.playing && inPlay) {
        if (el.playbackRate !== c.speed) el.playbackRate = c.speed;
        if (el.paused) {
          el.currentTime = target;
          el.play().catch(() => undefined);
        } else if (Math.abs(el.currentTime - target) > 0.3) {
          el.currentTime = target;
        }
      } else {
        if (!el.paused) el.pause();
        if (c.type === 'video' && Math.abs(e.lastSeek - target) > 0.001 && Math.abs(el.currentTime - target) > 0.02) {
          el.currentTime = target;
          e.lastSeek = target;
        }
      }
      if (e.gain && ac) {
        let v = 0;
        if (this.playing && inPlay && !c.muted && !track?.muted) {
          const l = t - c.start;
          let f = 1;
          if (c.fadeIn > 0) f = Math.min(f, l / c.fadeIn);
          if (c.fadeOut > 0) f = Math.min(f, (c.duration - l) / c.fadeOut);
          v = c.volume * clamp(f, 0, 1);
        }
        e.gain.gain.setTargetAtTime(v, ac.currentTime, 0.012);
      }
    }
    for (const id of [...this.els.keys()]) if (!seen.has(id)) this.destroy(id);
  }

  /** Wait (briefly) until video elements needed at time t have a decoded frame. */
  private async waitReady(t: number, timeout = 2500): Promise<void> {
    const start = performance.now();
    for (;;) {
      this.sync(t);
      const pending = store.project.clips.some((c) => {
        if (c.type !== 'video' || t < c.start || t >= clipEnd(c)) return false;
        const v = this.els.get(c.id)?.el as HTMLVideoElement | undefined;
        return !v || v.readyState < 2 || v.seeking;
      });
      if (!pending || performance.now() - start > timeout) return;
      await new Promise((r) => setTimeout(r, 50));
    }
  }

  /** The clip's video element once it has decoded the frame at the playhead. */
  async readyVideo(clip: Clip): Promise<HTMLVideoElement | null> {
    this.pause();
    await this.waitReady(store.time);
    const v = this.els.get(clip.id)?.el;
    return v instanceof HTMLVideoElement && v.readyState >= 2 && v.videoWidth > 0 && !v.seeking ? v : null;
  }

  /** Render the current frame at full project resolution. */
  async snapshot(width = store.project.width, height = store.project.height): Promise<HTMLCanvasElement> {
    await this.waitReady(store.time);
    const c = document.createElement('canvas');
    c.width = width;
    c.height = height;
    this.renderer.render(c, Math.min(store.time, Math.max(0, store.duration - 0.001)));
    return c;
  }

  // ------------------------------------------------------------ export

  async exportVideo(opts: ExportOptions, onProgress: (p: number) => void): Promise<Blob> {
    if (this.job) throw new Error('Export already running');
    const duration = store.duration;
    if (duration <= 0) throw new Error('Timeline is empty');
    this.pause();
    const ctx = this.ensureAudio();
    await ctx.resume();
    const canvas = document.createElement('canvas');
    canvas.width = opts.width;
    canvas.height = opts.height;
    const audioTracks = this.exportDest!.stream.getAudioTracks();
    let stream: MediaStream;
    if (opts.audioOnly) {
      stream = new MediaStream(audioTracks);
    } else {
      stream = canvas.captureStream(opts.fps);
      audioTracks.forEach((tr) => stream.addTrack(tr));
    }
    const recorder = new MediaRecorder(stream, {
      mimeType: opts.mimeType,
      videoBitsPerSecond: opts.videoBitsPerSecond,
      audioBitsPerSecond: 192_000,
    });
    const chunks: Blob[] = [];
    recorder.ondataavailable = (e) => {
      if (e.data.size) chunks.push(e.data);
    };
    store.setTime(0);
    await this.waitReady(0);
    this.renderer.render(canvas, 0);
    this.setSpeakersMuted(true);
    return new Promise<Blob>((resolve, reject) => {
      this.job = { canvas, recorder, stream, chunks, end: duration, mimeType: opts.mimeType, onProgress, resolve, reject };
      recorder.start(250);
      this.startT = 0;
      this.startPerf = performance.now();
      this.playing = true;
      store.playing = true;
      store.emit('play');
    });
  }

  private finishExport(cancelled = false): void {
    const job = this.job;
    if (!job) return;
    this.job = null;
    this.pause();
    this.setSpeakersMuted(false);
    job.recorder.onstop = () => {
      job.stream.getVideoTracks().forEach((t) => t.stop());
      if (cancelled) job.reject(new Error('Export cancelled'));
      else job.resolve(new Blob(job.chunks, { type: job.mimeType.split(';')[0] }));
    };
    if (job.recorder.state !== 'inactive') job.recorder.stop();
    else job.recorder.onstop?.(new Event('stop'));
  }

  cancelExport(): void {
    this.finishExport(true);
  }
}

export const engine = new Engine();
