// Core data model for the editor. Everything in `Project` is plain JSON so it
// can be snapshotted for undo/redo and persisted to IndexedDB.

export type MediaKind = 'video' | 'audio' | 'image';

export interface MediaItem {
  id: string;
  name: string;
  kind: MediaKind;
  /** Runtime-only object URL for the stored blob. */
  url: string;
  duration: number;
  width: number;
  height: number;
  hasAudio: boolean;
  /** Small JPEG data URLs sampled every `thumbInterval` seconds. */
  thumbs: string[];
  thumbInterval: number;
  /** Normalised audio peaks (0..1) sampled `waveRate` times per second. */
  waveform: number[] | null;
  waveRate: number;
  size: number;
  /** Set for procedurally generated library sounds. */
  builtin?: string;
}

export type TrackKind = 'video' | 'text' | 'audio' | 'effect';

export interface Track {
  id: string;
  kind: TrackKind;
  main?: boolean;
  muted: boolean;
  hidden: boolean;
  locked: boolean;
}

export type ClipType = 'video' | 'image' | 'audio' | 'text' | 'sticker' | 'effect';

export interface Transform {
  /** Offset from canvas centre, in project pixels. */
  x: number;
  y: number;
  /** 1 = fit inside the canvas. */
  scale: number;
  /** Degrees. */
  rotation: number;
  /** 0..1 */
  opacity: number;
}

export interface Keyframe extends Transform {
  /** Seconds relative to the clip start. */
  t: number;
}

export interface Adjust {
  brightness: number; // -1..1
  contrast: number; // -1..1
  saturation: number; // -1..1
  hue: number; // -180..180
  temperature: number; // -1..1
  tint: number; // -1..1
  vignette: number; // 0..1
  blur: number; // 0..40 px
}

export interface Crop {
  l: number;
  t: number;
  r: number;
  b: number;
}

export type MaskType = 'none' | 'linear' | 'mirror' | 'circle' | 'rect' | 'heart' | 'star';

export interface Mask {
  type: MaskType;
  x: number; // -1..1 of clip width
  y: number;
  size: number; // 0.05..2
  rotation: number;
  feather: number; // 0..1
  invert: boolean;
}

export interface Chroma {
  enabled: boolean;
  color: string;
  similarity: number; // 0..1
  smoothness: number; // 0..1
}

export interface Anim {
  type: string;
  duration: number;
}

export interface Transition {
  type: string;
  duration: number;
}

export interface TextStyle {
  content: string;
  font: string;
  size: number;
  color: string;
  bold: boolean;
  italic: boolean;
  underline: boolean;
  align: 'left' | 'center' | 'right';
  letterSpacing: number;
  lineHeight: number;
  strokeColor: string;
  strokeWidth: number;
  shadow: boolean;
  shadowColor: string;
  shadowBlur: number;
  shadowX: number;
  shadowY: number;
  bg: boolean;
  bgColor: string;
  bgOpacity: number;
  bgRadius: number;
}

export interface Clip {
  id: string;
  trackId: string;
  type: ClipType;
  mediaId?: string;
  name: string;
  /** Timeline position (seconds). */
  start: number;
  /** Timeline length (seconds). */
  duration: number;
  /** Source in-point (seconds) for video/audio. */
  in: number;
  speed: number;
  volume: number; // 0..2
  muted: boolean;
  fadeIn: number;
  fadeOut: number;
  transform: Transform;
  keyframes: Keyframe[];
  flipH: boolean;
  flipV: boolean;
  blend: string;
  crop: Crop;
  adjust: Adjust;
  filter: { id: string; intensity: number };
  mask: Mask;
  chroma: Chroma;
  animIn: Anim;
  animOut: Anim;
  /** Transition from the previous adjacent clip on the same track into this one. */
  transition: Transition | null;
  text?: TextStyle;
  emoji?: string;
  effect?: { id: string; intensity: number };
}

export interface Marker {
  id: string;
  t: number;
  color: string;
}

export interface Background {
  type: 'color' | 'blur';
  color: string;
  blur: number;
}

export interface Project {
  id: string;
  name: string;
  aspect: string;
  width: number;
  height: number;
  fps: number;
  background: Background;
  tracks: Track[];
  clips: Clip[];
  markers: Marker[];
  magnet: boolean;
  snap: boolean;
  created: number;
  updated: number;
}

export type Selection =
  | { kind: 'none' }
  | { kind: 'clips'; ids: string[] }
  | { kind: 'transition'; id: string };

export type ChangeReason = 'project' | 'time' | 'selection' | 'media' | 'zoom' | 'play' | 'load';
