// Editing operations (all undoable through store.commit).
import type { Clip, ClipType, MediaItem, Project, TextStyle, Transform } from './types';
import {
  baseClip,
  clipEnd,
  clone,
  findOrCreateTrack,
  isVisual,
  mainTrack,
  maxDuration,
  setTransformValue,
  toggleKeyframe,
  trackKindFor,
} from './model';
import { DEFAULT_TEXT, EFFECTS, TEXT_PRESETS } from './presets';
import { store } from './store';
import { engine } from './engine';
import { createMedia } from './media';
import { EPS, clamp, toast, uid } from './utils';

const MIN_DUR = 0.1;

function editable(c: Clip): boolean {
  return !store.track(c.trackId)?.locked;
}

/** Place a fully-formed clip on the timeline. */
function place(p: Project, clip: Clip, opts: { trackId?: string; time?: number; append?: boolean } = {}): void {
  const kind = trackKindFor(clip.type);
  const main = mainTrack(p);
  const target = opts.trackId ? p.tracks.find((t) => t.id === opts.trackId) : undefined;
  const toMain = kind === 'video' && (target ? target.main : opts.append || !p.clips.some((c) => c.trackId === main.id));
  if (toMain) {
    clip.trackId = main.id;
    const list = p.clips.filter((c) => c.trackId === main.id).sort((a, b) => a.start - b.start);
    if (p.magnet) {
      // Insert at the boundary closest to the requested time
      const t = opts.append || opts.time === undefined ? Infinity : opts.time;
      let insertAt = list.length;
      for (let i = 0; i < list.length; i++) {
        if (t < list[i].start + list[i].duration / 2) {
          insertAt = i;
          break;
        }
      }
      list.splice(insertAt, 0, clip);
      let cur = 0;
      for (const c of list) {
        c.start = cur;
        cur += c.duration;
      }
    } else {
      clip.start = opts.append ? list.reduce((m, c) => Math.max(m, clipEnd(c)), 0) : Math.max(0, opts.time ?? store.time);
    }
    p.clips.push(clip);
    return;
  }
  clip.start = Math.max(0, opts.time ?? store.time);
  clip.trackId = findOrCreateTrack(p, kind, clip.start, clip.duration, { prefer: target?.id }).id;
  p.clips.push(clip);
}

export function addMedia(m: MediaItem, opts: { trackId?: string; time?: number; append?: boolean } = {}): Clip {
  const type: ClipType = m.kind;
  const dur = m.kind === 'image' ? 5 : m.duration;
  const clip = baseClip(type, '', 0, dur, m.name);
  clip.mediaId = m.id;
  store.commit((p) => place(p, clip, { append: m.kind !== 'audio' && opts.trackId === undefined && opts.time === undefined, ...opts }), '', [clip.id]);
  store.select({ kind: 'clips', ids: [clip.id] });
  return clip;
}

export function addText(presetId = 'default', opts: { trackId?: string; time?: number; content?: string } = {}): Clip {
  const preset = TEXT_PRESETS.find((t) => t.id === presetId) ?? TEXT_PRESETS[0];
  const clip = baseClip('text', '', 0, 3, preset.label);
  clip.text = { ...DEFAULT_TEXT, ...preset.style, content: opts.content ?? (presetId === 'default' ? 'Your text' : preset.label) } as TextStyle;
  clip.name = clip.text.content;
  store.commit((p) => place(p, clip, opts), '', [clip.id]);
  store.select({ kind: 'clips', ids: [clip.id] });
  return clip;
}

export function addSticker(emoji: string, opts: { trackId?: string; time?: number } = {}): Clip {
  const clip = baseClip('sticker', '', 0, 3, `Sticker ${emoji}`);
  clip.emoji = emoji;
  store.commit((p) => place(p, clip, opts), '', [clip.id]);
  store.select({ kind: 'clips', ids: [clip.id] });
  return clip;
}

export function addEffect(id: string, opts: { trackId?: string; time?: number } = {}): Clip {
  const def = EFFECTS.find((e) => e.id === id) ?? EFFECTS[0];
  const clip = baseClip('effect', '', 0, 3, def.label);
  clip.effect = { id: def.id, intensity: 0.7 };
  store.commit((p) => place(p, clip, opts), '', [clip.id]);
  store.select({ kind: 'clips', ids: [clip.id] });
  return clip;
}

/** Drop handler used by the timeline: place an existing clip at a new position. */
export function moveClip(id: string, trackId: string, start: number): void {
  store.commit(
    (p) => {
      const c = p.clips.find((x) => x.id === id);
      if (!c) return;
      const tr = p.tracks.find((t) => t.id === trackId);
      if (!tr || tr.kind !== trackKindFor(c.type)) return;
      if (tr.main && p.magnet) {
        const others = p.clips.filter((x) => x.trackId === tr.id && x.id !== id).sort((a, b) => a.start - b.start);
        let idx = others.length;
        for (let i = 0; i < others.length; i++) {
          if (start < others[i].start + others[i].duration / 2) {
            idx = i;
            break;
          }
        }
        c.trackId = tr.id;
        others.splice(idx, 0, c);
        let cur = 0;
        for (const o of others) {
          o.start = cur;
          cur += o.duration;
        }
        if (c.transition && idx === 0) c.transition = null;
      } else {
        c.trackId = tr.id;
        c.start = Math.max(0, start);
      }
    },
    '',
    [id],
  );
}

export function moveToNewTrack(id: string, start: number, above = true): void {
  store.commit(
    (p) => {
      const c = p.clips.find((x) => x.id === id);
      if (!c) return;
      const kind = trackKindFor(c.type);
      const cur = p.tracks.find((t) => t.id === c.trackId);
      const t = { id: uid('t_'), kind, muted: false, hidden: false, locked: false };
      const idx = cur ? p.tracks.indexOf(cur) : 0;
      p.tracks.splice(above ? idx : idx + 1, 0, t);
      c.trackId = t.id;
      c.start = Math.max(0, start);
    },
    '',
    [id],
  );
}

export function trimClip(id: string, edge: 'l' | 'r', start: number, duration: number, inPoint: number): void {
  store.commit(
    (p) => {
      const c = p.clips.find((x) => x.id === id);
      if (!c) return;
      const shift = start - c.start;
      if (edge === 'l') c.keyframes.forEach((k) => (k.t -= shift));
      c.start = start;
      c.duration = duration;
      c.in = inPoint;
    },
    `trim:${id}`,
    [id],
  );
}

function targetClipsAt(t: number): Clip[] {
  const sel = store.selectedClips.filter((c) => t > c.start + EPS && t < clipEnd(c) - EPS);
  if (sel.length) return sel;
  if (store.selection.kind === 'clips' && store.selectedClips.length) return [];
  const main = mainTrack(store.project);
  return store.project.clips.filter((c) => c.trackId === main.id && t > c.start + EPS && t < clipEnd(c) - EPS);
}

export function splitAtPlayhead(): void {
  const t = store.time;
  const targets = targetClipsAt(t).filter(editable);
  if (!targets.length) {
    toast('Move the playhead over a clip to split it');
    return;
  }
  const newIds: string[] = [];
  store.commit((p) => {
    for (const tc of targets) {
      const c = p.clips.find((x) => x.id === tc.id)!;
      const cut = t - c.start;
      const b = clone(c);
      b.id = uid('c_');
      b.start = t;
      b.duration = c.duration - cut;
      b.in = c.in + cut * c.speed;
      b.transition = null;
      b.animIn = { type: 'none', duration: 0.5 };
      b.fadeIn = 0;
      b.keyframes = c.keyframes.filter((k) => k.t >= cut).map((k) => ({ ...k, t: k.t - cut }));
      c.duration = cut;
      c.animOut = { type: 'none', duration: 0.5 };
      c.fadeOut = 0;
      c.keyframes = c.keyframes.filter((k) => k.t <= cut);
      p.clips.push(b);
      newIds.push(b.id);
    }
  });
  store.select({ kind: 'clips', ids: newIds });
}

export function deleteSelection(): void {
  const s = store.selection;
  if (s.kind === 'transition') {
    store.commit((p) => {
      const c = p.clips.find((x) => x.id === s.id);
      if (c) c.transition = null;
    });
    store.select({ kind: 'none' });
    return;
  }
  const ids = new Set(store.selectedClips.filter(editable).map((c) => c.id));
  if (!ids.size) return;
  store.commit((p) => {
    p.clips = p.clips.filter((c) => !ids.has(c.id));
  });
  store.select({ kind: 'none' });
}

/** Q / W: remove the part of the clip before / after the playhead. */
export function trimToPlayhead(side: 'left' | 'right'): void {
  const t = store.time;
  const targets = targetClipsAt(t).filter(editable);
  if (!targets.length) return;
  store.commit((p) => {
    for (const tc of targets) {
      const c = p.clips.find((x) => x.id === tc.id)!;
      const cut = t - c.start;
      if (side === 'left') {
        c.in += cut * c.speed;
        c.keyframes = c.keyframes.filter((k) => k.t >= cut).map((k) => ({ ...k, t: k.t - cut }));
        c.start = t;
        c.duration -= cut;
        c.transition = null;
      } else {
        c.duration = cut;
        c.keyframes = c.keyframes.filter((k) => k.t <= cut);
      }
    }
  });
}

let clipboard: Clip[] = [];

export function copySelection(): void {
  clipboard = clone(store.selectedClips);
  if (clipboard.length) toast(`Copied ${clipboard.length} clip${clipboard.length > 1 ? 's' : ''}`);
}

export function paste(): void {
  if (!clipboard.length) return;
  const base = Math.min(...clipboard.map((c) => c.start));
  const ids: string[] = [];
  store.commit((p) => {
    for (const src of clipboard) {
      const c = clone(src);
      c.id = uid('c_');
      c.transition = null;
      const time = store.time + (src.start - base);
      const tr = p.tracks.find((t) => t.id === src.trackId);
      place(p, c, { time, trackId: tr && !tr.main ? tr.id : undefined });
      ids.push(c.id);
    }
  }, '', ids);
  store.select({ kind: 'clips', ids });
}

export function duplicateSelection(): void {
  const sel = store.selectedClips;
  if (!sel.length) return;
  const ids: string[] = [];
  store.commit((p) => {
    for (const src of sel) {
      const c = clone(src);
      c.id = uid('c_');
      c.transition = null;
      const tr = p.tracks.find((t) => t.id === src.trackId)!;
      if (tr.main && p.magnet) {
        c.start = clipEnd(src) - EPS;
        c.trackId = tr.id;
        p.clips.push(c);
        // keep ordering right after the source
        p.clips.filter((x) => x.trackId === tr.id && x.start >= clipEnd(src) - EPS && x.id !== c.id).forEach((x) => (x.start += c.duration));
      } else {
        c.start = clipEnd(src);
        c.trackId = tr.id;
        p.clips.push(c);
      }
      ids.push(c.id);
    }
  }, '', ids);
  store.select({ kind: 'clips', ids });
}

export async function freezeFrame(): Promise<void> {
  const t = store.time;
  const c = store.selectedClip ?? targetClipsAt(t).find((x) => x.type === 'video');
  if (!c || c.type !== 'video' || t < c.start || t >= clipEnd(c)) {
    toast('Select a video clip under the playhead to freeze a frame');
    return;
  }
  const el = await engine.readyVideo(c);
  if (!el) {
    toast('Could not read that frame — try again', 'error');
    return;
  }
  const canvas = document.createElement('canvas');
  canvas.width = el.videoWidth;
  canvas.height = el.videoHeight;
  canvas.getContext('2d')!.drawImage(el, 0, 0);
  const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, 'image/png'));
  if (!blob) return;
  const media = await createMedia(blob, `Freeze ${c.name}.png`, { kind: 'image' });
  if (!media) return;
  const freeze = baseClip('image', c.trackId, t, 3, 'Freeze frame');
  freeze.mediaId = media.id;
  Object.assign(freeze, clone({ transform: c.transform, flipH: c.flipH, flipV: c.flipV, crop: c.crop, adjust: c.adjust, filter: c.filter, mask: c.mask, blend: c.blend }));
  store.commit((p) => {
    const orig = p.clips.find((x) => x.id === c.id)!;
    const cut = t - orig.start;
    const track = p.tracks.find((x) => x.id === orig.trackId)!;
    if (!track.main || !p.magnet) {
      p.clips.filter((x) => x.trackId === track.id && x.start >= t + EPS).forEach((x) => (x.start += 3));
    }
    if (cut > EPS && cut < orig.duration - EPS) {
      const b = clone(orig);
      b.id = uid('c_');
      b.start = t + 3;
      b.duration = orig.duration - cut;
      b.in = orig.in + cut * orig.speed;
      b.transition = null;
      b.keyframes = [];
      orig.duration = cut;
      p.clips.push(b);
    }
    freeze.start = t + (track.main && p.magnet ? EPS : 0);
    p.clips.push(freeze);
  }, '', [freeze.id]);
  store.select({ kind: 'clips', ids: [freeze.id] });
  toast('Freeze frame added (3s)', 'success');
}

export function detachAudio(): void {
  const c = store.selectedClip;
  const m = c?.mediaId ? store.media.get(c.mediaId) : undefined;
  if (!c || c.type !== 'video' || !m?.hasAudio) {
    toast('Select a video clip that has sound');
    return;
  }
  const a = baseClip('audio', '', c.start, c.duration, `${c.name} (audio)`);
  Object.assign(a, { mediaId: c.mediaId, in: c.in, speed: c.speed, volume: c.volume, fadeIn: c.fadeIn, fadeOut: c.fadeOut });
  store.commit((p) => {
    const v = p.clips.find((x) => x.id === c.id)!;
    v.muted = true;
    a.trackId = findOrCreateTrack(p, 'audio', a.start, a.duration).id;
    p.clips.push(a);
  }, '', [a.id]);
  toast('Audio extracted to its own track', 'success');
}

export function setTransition(clipId: string, type: string, duration?: number): void {
  store.commit((p) => {
    const c = p.clips.find((x) => x.id === clipId);
    if (!c) return;
    c.transition = type === 'none' ? null : { type, duration: duration ?? c.transition?.duration ?? 0.6 };
  }, `trans:${clipId}`);
}

export function applyTransitionToAll(type: string, duration: number): void {
  store.commit((p) => {
    for (const tr of p.tracks.filter((t) => t.kind === 'video')) {
      const list = p.clips.filter((c) => c.trackId === tr.id).sort((a, b) => a.start - b.start);
      for (let i = 1; i < list.length; i++) {
        if (Math.abs(clipEnd(list[i - 1]) - list[i].start) < 1e-3) list[i].transition = { type, duration };
      }
    }
  });
  toast('Transition applied to every cut', 'success');
}

export function addMarker(): void {
  const t = store.time;
  store.commit((p) => {
    const existing = p.markers.find((m) => Math.abs(m.t - t) < 0.05);
    if (existing) p.markers = p.markers.filter((m) => m !== existing);
    else p.markers.push({ id: uid('k_'), t, color: '#ffb020' });
  });
}

/** Update fields on one or more clips. */
export function updateClips(ids: string[], fn: (c: Clip) => void, key = ''): void {
  store.commit((p) => {
    for (const id of ids) {
      const c = p.clips.find((x) => x.id === id);
      if (c) fn(c);
    }
  }, key, ids);
}

export function updateClip(id: string, fn: (c: Clip) => void, key = ''): void {
  updateClips([id], fn, key);
}

export function setTransformProp(id: string, prop: keyof Transform, value: number): void {
  updateClip(
    id,
    (c) => setTransformValue(c, prop, value, store.time - c.start),
    `tf:${id}:${prop}`,
  );
}

export function toggleKeyframeAtPlayhead(id: string): void {
  updateClip(id, (c) => {
    const added = toggleKeyframe(c, store.time - c.start);
    toast(added ? 'Keyframe added' : 'Keyframe removed');
  });
}

export function setSpeed(id: string, speed: number): void {
  updateClip(
    id,
    (c) => {
      const m = c.mediaId ? store.media.get(c.mediaId) : undefined;
      const span = c.duration * c.speed;
      c.speed = clamp(speed, 0.1, 10);
      c.duration = Math.max(MIN_DUR, span / c.speed);
      c.duration = Math.min(c.duration, maxDuration(c, m));
      c.keyframes = [];
    },
    `speed:${id}`,
  );
}

export function deleteMedia(id: string): void {
  const used = store.project.clips.some((c) => c.mediaId === id);
  if (used) {
    store.commit((p) => {
      p.clips = p.clips.filter((c) => c.mediaId !== id);
    });
  }
  store.removeMedia(id);
}

export function visualClipsAt(t: number): Clip[] {
  // top-most first
  const order = store.project.tracks.filter((tr) => !tr.hidden).map((tr) => tr.id);
  return store.project.clips
    .filter((c) => isVisual(c) && t >= c.start && t < clipEnd(c) && order.includes(c.trackId))
    .sort((a, b) => order.indexOf(a.trackId) - order.indexOf(b.trackId));
}
