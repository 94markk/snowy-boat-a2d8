// Export dialog: renders the timeline in real time into MediaRecorder.
import { engine } from './engine';
import { store } from './store';
import { $, downloadBlob, fmtBytes, h, shortTime, toast } from './utils';

interface Format {
  id: string;
  label: string;
  mimes: string[];
  ext: string;
  audioOnly?: boolean;
}

const FORMATS: Format[] = [
  { id: 'mp4', label: 'MP4 · H.264 + AAC', mimes: ['video/mp4;codecs=avc1.640028,mp4a.40.2', 'video/mp4;codecs=avc1.42E01E,mp4a.40.2', 'video/mp4;codecs=avc1,mp4a', 'video/mp4'], ext: 'mp4' },
  { id: 'webm9', label: 'WebM · VP9 + Opus', mimes: ['video/webm;codecs=vp9,opus'], ext: 'webm' },
  { id: 'webm8', label: 'WebM · VP8 + Opus', mimes: ['video/webm;codecs=vp8,opus', 'video/webm'], ext: 'webm' },
  { id: 'audio', label: 'Audio only · Opus', mimes: ['audio/webm;codecs=opus', 'audio/webm'], ext: 'webm', audioOnly: true },
  { id: 'audio-m4a', label: 'Audio only · AAC', mimes: ['audio/mp4;codecs=mp4a.40.2', 'audio/mp4'], ext: 'm4a', audioOnly: true },
];

const supportedMime = (f: Format) => (typeof MediaRecorder === 'undefined' ? undefined : f.mimes.find((m) => MediaRecorder.isTypeSupported(m)));

export class Exporter {
  private dlg = $<HTMLDialogElement>('#export-dialog');
  private form = $<HTMLFormElement>('#export-form');
  private format = $<HTMLSelectElement>('#export-format');
  private est = $('#export-est');
  private progress = $('#export-progress');
  private bar = $('#export-bar');
  private status = $('#export-status');
  private startBtn = $<HTMLButtonElement>('#export-start');
  private cancelBtn = $<HTMLButtonElement>('#export-cancel');
  private cover = $<HTMLImageElement>('#export-cover');
  private running = false;
  private lastBlob: { blob: Blob; name: string } | null = null;

  constructor() {
    const supported = FORMATS.filter(supportedMime);
    this.format.replaceChildren(...supported.map((f) => h('option', { value: f.id }, f.label)));
    if (!supported.length) this.format.append(h('option', { value: '' }, 'Not supported in this browser'));
    $('#btn-export').addEventListener('click', () => void this.open());
    this.form.addEventListener('input', () => this.estimate());
    this.startBtn.addEventListener('click', () => void this.start());
    this.cancelBtn.addEventListener('click', () => {
      if (this.running) engine.cancelExport();
      else this.dlg.close();
    });
    this.dlg.addEventListener('cancel', (e) => {
      if (this.running) e.preventDefault();
    });
    $('#export-close').addEventListener('click', () => {
      if (!this.running) this.dlg.close();
    });
  }

  private field(name: string): string {
    return (this.form.elements.namedItem(name) as HTMLInputElement | HTMLSelectElement).value;
  }

  private dims(): { w: number; h: number } {
    const p = store.project;
    const short = +this.field('res');
    const ar = p.width / p.height;
    const even = (n: number) => Math.max(2, Math.round(n / 2) * 2);
    return ar >= 1 ? { w: even(short * ar), h: even(short) } : { w: even(short), h: even(short / ar) };
  }

  private bitrate(): number {
    const { w, h: hh } = this.dims();
    const q = { low: 0.05, rec: 0.1, high: 0.2 }[this.field('quality') as 'low' | 'rec' | 'high'] ?? 0.1;
    return Math.round(Math.min(80e6, Math.max(1e6, w * hh * +this.field('fps') * q)));
  }

  private estimate(): void {
    const f = FORMATS.find((x) => x.id === this.field('format'));
    const { w, h: hh } = this.dims();
    const audioOnly = !!f?.audioOnly;
    this.form.classList.toggle('is-audio', audioOnly);
    const bps = (audioOnly ? 0 : this.bitrate()) + 192_000;
    const size = (bps * store.duration) / 8;
    this.est.textContent = audioOnly
      ? `${shortTime(store.duration)} · about ${fmtBytes(size)}`
      : `${w}×${hh} · ${this.field('fps')} fps · ${(this.bitrate() / 1e6).toFixed(1)} Mbps · ${shortTime(store.duration)} · about ${fmtBytes(size)}`;
  }

  async open(): Promise<void> {
    if (!store.project.clips.length) {
      toast('Add something to the timeline before exporting');
      return;
    }
    if (this.running) {
      this.dlg.showModal();
      return;
    }
    (this.form.elements.namedItem('filename') as HTMLInputElement).value = store.project.name;
    (this.form.elements.namedItem('fps') as HTMLSelectElement).value = String(store.project.fps);
    this.progress.hidden = true;
    this.form.classList.remove('is-running', 'is-done');
    this.startBtn.disabled = !this.format.value;
    this.startBtn.textContent = 'Export';
    this.estimate();
    this.dlg.showModal();
    try {
      const c = await engine.snapshot(480, Math.round((480 * store.project.height) / store.project.width));
      this.cover.src = c.toDataURL('image/jpeg', 0.8);
    } catch {
      /* ignore */
    }
  }

  private async start(): Promise<void> {
    if (this.form.classList.contains('is-done') && this.lastBlob) {
      downloadBlob(this.lastBlob.blob, this.lastBlob.name);
      return;
    }
    const f = FORMATS.find((x) => x.id === this.field('format'));
    const mime = f && supportedMime(f);
    if (!f || !mime) {
      toast('This browser cannot record that format', 'error');
      return;
    }
    const { w, h: hh } = this.dims();
    const name = `${(this.field('filename') || 'video').replace(/[\\/:*?"<>|]+/g, '_')}.${f.ext}`;
    this.running = true;
    this.form.classList.add('is-running');
    this.progress.hidden = false;
    this.startBtn.disabled = true;
    this.cancelBtn.textContent = 'Cancel export';
    this.bar.style.width = '0%';
    this.status.textContent = 'Preparing…';
    const t0 = performance.now();
    try {
      const blob = await engine.exportVideo(
        { width: w, height: hh, fps: +this.field('fps'), mimeType: mime, videoBitsPerSecond: this.bitrate(), audioOnly: !!f.audioOnly },
        (p) => {
          this.bar.style.width = `${(p * 100).toFixed(1)}%`;
          const left = Math.max(0, store.duration * (1 - p));
          this.status.textContent = `Rendering ${Math.round(p * 100)}% · ${shortTime(left)} left — keep this tab visible`;
        },
      );
      this.lastBlob = { blob, name };
      downloadBlob(blob, name);
      this.bar.style.width = '100%';
      this.status.textContent = `Done in ${shortTime((performance.now() - t0) / 1000)} · ${fmtBytes(blob.size)} · ${name}`;
      this.form.classList.add('is-done');
      this.startBtn.textContent = 'Download again';
      this.startBtn.disabled = false;
      toast('Export complete', 'success');
    } catch (e) {
      this.status.textContent = (e as Error).message;
      this.startBtn.disabled = false;
    } finally {
      this.running = false;
      this.form.classList.remove('is-running');
      this.cancelBtn.textContent = 'Close';
    }
  }
}
