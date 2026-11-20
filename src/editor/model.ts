// Pure helpers over the project data model (no DOM).
import type { Clip, ClipType, Keyframe, MediaItem, Project, Track, TrackKind, Transform } from './types';
import { ASPECTS, DEFAULT_TEXT, ZERO_ADJUST } from './presets';
import { EPS, clamp, easing, lerp, uid } from './utils';

export const clipEnd = (c: Clip): number => c.start + c.duration;

export const trackKindFor = (type: ClipType): TrackKind =>
  type === 'video' || type === 'image'
    ? 'video'
    : type === 'audio'
      ? 'audio'
      : type === 'effect'
        ? 'effect'
        : 'text';

export const isVisual = (c: Clip): boolean => c.type !== 'audio' && c.type !== 'effect';
export const hasSourceTime = (c: Clip): boolean => c.type === 'video' || c.type === 'audio';

const KIND_RANK: Record<TrackKind, number> = { effect: 0, text: 1, video: 2, audio: 4 };
const trackRank = (t: Track): number => (t.main ? 3 : KIND_RANK[t.kind]);

export function makeTrack(kind: TrackKind, main = false): Track {
  return { id: uid('t_'), kind, main: main || undefined, muted: false, hidden: false, locked: false };
}

export function newProject(name = 'Untitled project'): Project {
  const a = ASPECTS[0];
  const now = Date.now();
  return {
    id: uid('p_'),
    name,
    aspect: a.id,
    width: a.w,
    height: a.h,
    fps: 30,
    background: { type: 'color', color: '#000000', blur: 40 },
    tracks: [makeTrack('video', true)],
    clips: [],
    markers: [],
    magnet: true,
    snap: true,
    created: now,
    updated: now,
  };
}

export function baseClip(type: ClipType, trackId: string, start: number, duration: number, name: string): Clip {
  return {
    id: uid('c_'),
    trackId,
    type,
    name,
    start,
    duration,
    in: 0,
    speed: 1,
    volume: 1,
    muted: false,
    fadeIn: 0,
    fadeOut: 0,
    transform: { x: 0, y: 0, scale: 1, rotation: 0, opacity: 1 },
    keyframes: [],
    flipH: false,
    flipV: false,
    blend: 'source-over',
    crop: { l: 0, t: 0, r: 0, b: 0 },
    adjust: { ...ZERO_ADJUST },
    filter: { id: 'none', intensity: 1 },
    mask: { type: 'none', x: 0, y: 0, size: 0.6, rotation: 0, feather: 0, invert: false },
    chroma: { enabled: false, color: '#00ff00', similarity: 0.35, smoothness: 0.1 },
    animIn: { type: 'none', duration: 0.5 },
    animOut: { type: 'none', duration: 0.5 },
    transition: null,
    text: type === 'text' ? { ...DEFAULT_TEXT } : undefined,
  };
}

/** Longest timeline duration the clip may have given its source media. */
export function maxDuration(c: Clip, media: MediaItem | undefined): number {
  if (!hasSourceTime(c) || !media) return Infinity;
  return Math.max(0.05, (media.duration - c.in) / c.speed);
}

/** Interpolated transform at a clip-local time (keyframes aware). */
export function getTransformAt(c: Clip, local: number): Transform {
  const k = c.keyframes;
  if (!k.length) return c.transform;
  if (local <= k[0].t) return pickT(k[0]);
  const last = k[k.length - 1];
  if (local >= last.t) return pickT(last);
  for (let i = 1; i < k.length; i++) {
    if (local <= k[i].t) {
      const a = k[i - 1];
      const b = k[i];
      const p = easing.inOutCubic((local - a.t) / Math.max(EPS, b.t - a.t));
      return {
        x: lerp(a.x, b.x, p),
        y: lerp(a.y, b.y, p),
        scale: lerp(a.scale, b.scale, p),
        rotation: lerp(a.rotation, b.rotation, p),
        opacity: lerp(a.opacity, b.opacity, p),
      };
    }
  }
  return pickT(last);
}

const pickT = (k: Keyframe): Transform => ({ x: k.x, y: k.y, scale: k.scale, rotation: k.rotation, opacity: k.opacity });

export const KF_TOL = 1 / 60;

export function keyframeAt(c: Clip, local: number): Keyframe | undefined {
  return c.keyframes.find((k) => Math.abs(k.t - local) < KF_TOL);
}

/** Set one transform property, writing into a keyframe when the clip is keyframed. */
export function setTransformValue(c: Clip, prop: keyof Transform, value: number, local: number): void {
  if (!c.keyframes.length) {
    c.transform[prop] = value;
    return;
  }
  local = clamp(local, 0, c.duration);
  let kf = keyframeAt(c, local);
  if (!kf) {
    kf = { t: local, ...getTransformAt(c, local) };
    c.keyframes.push(kf);
    c.keyframes.sort((a, b) => a.t - b.t);
  }
  kf[prop] = value;
}

export function toggleKeyframe(c: Clip, local: number): boolean {
  local = clamp(local, 0, c.duration);
  const kf = keyframeAt(c, local);
  if (kf) {
    const cur = getTransformAt(c, local);
    c.keyframes = c.keyframes.filter((k) => k !== kf);
    if (!c.keyframes.length) c.transform = { ...cur };
    return false;
  }
  c.keyframes.push({ t: local, ...getTransformAt(c, local) });
  c.keyframes.sort((a, b) => a.t - b.t);
  return true;
}

export function clipsOnTrack(p: Project, trackId: string): Clip[] {
  return p.clips.filter((c) => c.trackId === trackId).sort((a, b) => a.start - b.start);
}

export function mainTrack(p: Project): Track {
  return p.tracks.find((t) => t.main)!;
}

export function projectDuration(p: Project): number {
  let d = 0;
  for (const c of p.clips) d = Math.max(d, clipEnd(c));
  return d;
}

export function fits(p: Project, trackId: string, start: number, duration: number, ignore?: Set<string>): boolean {
  return !p.clips.some(
    (c) =>
      c.trackId === trackId &&
      !ignore?.has(c.id) &&
      start < clipEnd(c) - EPS &&
      start + duration > c.start + EPS,
  );
}

/** Insert a new track of `kind` next to `near` (above for visual kinds, below for audio). */
export function insertTrack(p: Project, kind: TrackKind, near?: Track): Track {
  const t = makeTrack(kind);
  let idx = near ? p.tracks.indexOf(near) : -1;
  if (idx < 0) {
    // Default: closest to main track (visual) or bottom (audio)
    idx = kind === 'audio' ? p.tracks.length : p.tracks.findIndex((x) => trackRank(x) >= KIND_RANK[kind]);
    if (idx < 0) idx = p.tracks.length;
    p.tracks.splice(idx, 0, t);
  } else {
    p.tracks.splice(kind === 'audio' ? idx + 1 : idx, 0, t);
  }
  return t;
}

/** Find a track of `kind` with free space, else create one. */
export function findOrCreateTrack(
  p: Project,
  kind: TrackKind,
  start: number,
  duration: number,
  opts: { prefer?: string; allowMain?: boolean; ignore?: Set<string> } = {},
): Track {
  const pref = opts.prefer ? p.tracks.find((t) => t.id === opts.prefer) : undefined;
  if (pref && pref.kind === kind && fits(p, pref.id, start, duration, opts.ignore)) return pref;
  const cands = p.tracks.filter((t) => t.kind === kind && (!t.main || opts.allowMain) && !t.locked);
  // Visual kinds: search nearest to the main track first (bottom-up of the overlay stack)
  if (kind !== 'audio') cands.reverse();
  for (const t of cands) if (fits(p, t.id, start, duration, opts.ignore)) return t;
  return insertTrack(p, kind, pref && pref.kind === kind ? pref : undefined);
}

/** Pack main-track clips back-to-back from 0 (magnetic timeline). */
export function packMain(p: Project): void {
  const m = mainTrack(p);
  let cursor = 0;
  for (const c of clipsOnTrack(p, m.id)) {
    c.start = cursor;
    cursor += c.duration;
  }
}

/** Move overlapping clips to other tracks. Clips in `prefer` move first. */
function resolveOverlaps(p: Project, prefer: Set<string>): void {
  for (let guard = 0; guard < 200; guard++) {
    let moved = false;
    for (const tr of p.tracks) {
      if (tr.main && p.magnet) continue;
      const list = clipsOnTrack(p, tr.id);
      for (let i = 1; i < list.length; i++) {
        const a = list[i - 1];
        const b = list[i];
        if (b.start < clipEnd(a) - EPS) {
          const mover = prefer.has(a.id) && !prefer.has(b.id) ? a : b;
          const target = findOrCreateTrack(p, tr.kind, mover.start, mover.duration, {
            ignore: new Set([mover.id]),
            allowMain: !p.magnet,
          });
          mover.trackId = target.id;
          moved = true;
          break;
        }
      }
      if (moved) break;
    }
    if (!moved) return;
  }
}

/** Keep the project consistent after any edit. */
export function normalize(p: Project, prefer: Set<string> = new Set()): void {
  if (!p.tracks.some((t) => t.main)) p.tracks.unshift(makeTrack('video', true));
  // Clips must sit on a track of the matching kind
  for (const c of p.clips) {
    const tr = p.tracks.find((t) => t.id === c.trackId);
    const kind = trackKindFor(c.type);
    if (!tr || tr.kind !== kind) {
      c.trackId = findOrCreateTrack(p, kind, c.start, c.duration, { allowMain: kind === 'video' }).id;
    }
    c.start = Math.max(0, c.start);
    c.duration = Math.max(0.05, c.duration);
  }
  if (p.magnet) packMain(p);
  resolveOverlaps(p, prefer);
  if (p.magnet) packMain(p);
  // Drop empty non-main tracks
  const used = new Set(p.clips.map((c) => c.trackId));
  p.tracks = p.tracks.filter((t) => t.main || used.has(t.id));
  // Stable sort by kind rank (effects, text, overlays, main, audio)
  p.tracks = p.tracks
    .map((t, i) => ({ t, i }))
    .sort((a, b) => trackRank(a.t) - trackRank(b.t) || a.i - b.i)
    .map((x) => x.t);
  // Transitions only between adjacent clips and no longer than either clip
  for (const tr of p.tracks) {
    const list = clipsOnTrack(p, tr.id);
    list.forEach((c, i) => {
      if (!c.transition) return;
      const prev = list[i - 1];
      if (!prev || Math.abs(clipEnd(prev) - c.start) > 1e-3 || tr.kind !== 'video') {
        c.transition = null;
        return;
      }
      c.transition.duration = clamp(c.transition.duration, 0.1, Math.min(prev.duration, c.duration));
    });
  }
  for (const c of p.clips) {
    c.keyframes = c.keyframes.filter((k) => k.t >= -KF_TOL && k.t <= c.duration + KF_TOL);
    c.animIn.duration = clamp(c.animIn.duration, 0.1, Math.max(0.1, c.duration));
    c.animOut.duration = clamp(c.animOut.duration, 0.1, Math.max(0.1, c.duration));
  }
}

/** Previous adjacent clip on the same track (for transitions). */
export function prevAdjacent(p: Project, c: Clip): Clip | undefined {
  return p.clips.find((o) => o.trackId === c.trackId && o.id !== c.id && Math.abs(clipEnd(o) - c.start) < 1e-3);
}

export function nextAdjacent(p: Project, c: Clip): Clip | undefined {
  return p.clips.find((o) => o.trackId === c.trackId && o.id !== c.id && Math.abs(o.start - clipEnd(c)) < 1e-3);
}

export function clone<T>(o: T): T {
  return JSON.parse(JSON.stringify(o)) as T;
}
