// Media import: probing, thumbnails and waveform peaks.
import type { MediaItem, MediaKind } from './types';
import { saveMedia } from './db';
import { store } from './store';
import { toast, uid } from './utils';

const THUMB_H = 72;
const MAX_THUMBS = 40;
const WAVE_RATE = 40;

function kindOf(file: Blob, name: string): MediaKind | null {
  const t = file.type;
  if (t.startsWith('video/')) return 'video';
  if (t.startsWith('audio/')) return 'audio';
  if (t.startsWith('image/')) return 'image';
  const ext = name.split('.').pop()?.toLowerCase() ?? '';
  if (['mp4', 'webm', 'mov', 'mkv', 'm4v', 'ogv'].includes(ext)) return 'video';
  if (['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'opus', 'weba'].includes(ext)) return 'audio';
  if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif', 'svg'].includes(ext)) return 'image';
  return null;
}

function once<K extends keyof HTMLMediaElementEventMap>(el: HTMLMediaElement, ev: K, ms = 15000): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`timeout waiting for ${ev}`)), ms);
    el.addEventListener(
      ev,
      () => {
        clearTimeout(timer);
        resolve();
      },
      { once: true },
    );
    el.addEventListener(
      'error',
      () => {
        clearTimeout(timer);
        reject(new Error('media error'));
      },
      { once: true },
    );
  });
}

/** MediaRecorder WebM files report Infinity duration until scanned. */
async function finiteDuration(el: HTMLMediaElement): Promise<number> {
  if (Number.isFinite(el.duration) && el.duration > 0) return el.duration;
  el.currentTime = 1e7;
  await Promise.race([once(el, 'durationchange', 4000), once(el, 'seeked', 4000)]).catch(() => undefined);
  const d = el.duration;
  el.currentTime = 0;
  return Number.isFinite(d) ? d : 0;
}

async function probeVideo(url: string) {
  const v = document.createElement('video');
  v.preload = 'auto';
  v.muted = true;
  v.playsInline = true;
  v.src = url;
  await once(v, 'loadedmetadata');
  const duration = await finiteDuration(v);
  const width = v.videoWidth || 1280;
  const height = v.videoHeight || 720;
  const count = Math.min(MAX_THUMBS, Math.max(1, Math.ceil(duration / 1)));
  const interval = duration / count;
  const thumbs: string[] = [];
  const c = document.createElement('canvas');
  c.height = THUMB_H;
  c.width = Math.round((THUMB_H * width) / height);
  const ctx = c.getContext('2d')!;
  for (let i = 0; i < count; i++) {
    const t = Math.min(duration - 0.05, i * interval + interval * 0.2);
    try {
      v.currentTime = Math.max(0, t);
      await once(v, 'seeked', 5000);
      ctx.drawImage(v, 0, 0, c.width, c.height);
      thumbs.push(c.toDataURL('image/jpeg', 0.6));
    } catch {
      break;
    }
  }
  v.removeAttribute('src');
  v.load();
  return { duration, width, height, thumbs, interval: interval || 1 };
}

async function probeAudio(url: string) {
  const a = document.createElement('audio');
  a.preload = 'metadata';
  a.src = url;
  await once(a, 'loadedmetadata');
  return { duration: await finiteDuration(a) };
}

async function probeImage(url: string) {
  const img = new Image();
  img.src = url;
  await img.decode();
  const c = document.createElement('canvas');
  c.height = THUMB_H * 2;
  c.width = Math.max(1, Math.round((c.height * img.naturalWidth) / img.naturalHeight));
  c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
  return { width: img.naturalWidth, height: img.naturalHeight, thumb: c.toDataURL('image/jpeg', 0.75) };
}

async function computeWaveform(blob: Blob): Promise<{ peaks: number[]; duration: number } | null> {
  if (blob.size > 400 * 1024 * 1024) return null;
  try {
    const buf = await blob.arrayBuffer();
    const ctx = new OfflineAudioContext(1, 1, 44100);
    const audio = await ctx.decodeAudioData(buf);
    const ch0 = audio.getChannelData(0);
    const ch1 = audio.numberOfChannels > 1 ? audio.getChannelData(1) : ch0;
    const bucket = Math.max(1, Math.floor(audio.sampleRate / WAVE_RATE));
    const n = Math.ceil(ch0.length / bucket);
    const peaks = new Array<number>(n);
    let max = 0;
    for (let i = 0; i < n; i++) {
      let p = 0;
      const end = Math.min(ch0.length, (i + 1) * bucket);
      for (let j = i * bucket; j < end; j += 4) {
        const v = Math.max(Math.abs(ch0[j]), Math.abs(ch1[j]));
        if (v > p) p = v;
      }
      peaks[i] = p;
      if (p > max) max = p;
    }
    // Treat near-silence (below about -50 dBFS) as no audio instead of amplifying noise.
    if (max < 0.003) return { peaks: peaks.map(() => 0), duration: audio.duration };
    const norm = 1 / max;
    return { peaks: peaks.map((p) => Math.round(p * norm * 100) / 100), duration: audio.duration };
  } catch {
    return null;
  }
}

/** Create a media item from a blob, persist it, and add it to the library. */
export async function createMedia(blob: Blob, name: string, opts: { builtin?: string; kind?: MediaKind } = {}): Promise<MediaItem | null> {
  const kind = opts.kind ?? kindOf(blob, name);
  if (!kind) {
    toast(`Unsupported file: ${name}`, 'error');
    return null;
  }
  const url = URL.createObjectURL(blob);
  const item: MediaItem = {
    id: uid('m_'),
    name,
    kind,
    url,
    duration: 0,
    width: 0,
    height: 0,
    hasAudio: false,
    thumbs: [],
    thumbInterval: 1,
    waveform: null,
    waveRate: WAVE_RATE,
    size: blob.size,
    builtin: opts.builtin,
  };
  try {
    if (kind === 'video') {
      const [v, w] = await Promise.all([probeVideo(url), computeWaveform(blob)]);
      Object.assign(item, { duration: v.duration, width: v.width, height: v.height, thumbs: v.thumbs, thumbInterval: v.interval });
      if (w) {
        item.waveform = w.peaks;
        item.hasAudio = w.peaks.some((p) => p > 0.01);
      }
    } else if (kind === 'audio') {
      const [a, w] = await Promise.all([probeAudio(url), computeWaveform(blob)]);
      item.duration = a.duration || w?.duration || 0;
      item.hasAudio = true;
      item.waveform = w?.peaks ?? null;
    } else {
      const i = await probeImage(url);
      Object.assign(item, { width: i.width, height: i.height, thumbs: [i.thumb], duration: 5 });
    }
    if (kind !== 'image' && !(item.duration > 0)) throw new Error('Could not read duration');
  } catch (e) {
    URL.revokeObjectURL(url);
    toast(`Couldn't import ${name}: ${(e as Error).message}`, 'error');
    return null;
  }
  store.addMedia(item);
  saveMedia(store.project.id, item, blob).catch(() => toast('Storage is full — media will not persist after reload', 'error'));
  return item;
}

export async function importFiles(files: Iterable<File>): Promise<MediaItem[]> {
  const out: MediaItem[] = [];
  const list = Array.from(files);
  if (!list.length) return out;
  document.body.classList.add('is-importing');
  try {
    for (const f of list) {
      const m = await createMedia(f, f.name);
      if (m) out.push(m);
    }
  } finally {
    document.body.classList.remove('is-importing');
  }
  if (out.length) toast(`Imported ${out.length} file${out.length > 1 ? 's' : ''}`, 'success');
  return out;
}

export function pickFiles(accept = 'video/*,audio/*,image/*'): Promise<File[]> {
  return new Promise((resolve) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.accept = accept;
    input.onchange = () => resolve(Array.from(input.files ?? []));
    input.click();
  });
}
