// Built-in, royalty-free sound library synthesised in the browser with
// OfflineAudioContext and encoded as WAV.
import type { MediaItem } from './types';
import { SOUNDS } from './presets';
import { createMedia } from './media';
import { store } from './store';

const SR = 44100;

type Build = (ctx: OfflineAudioContext, out: AudioNode) => void;

function noiseBuffer(ctx: BaseAudioContext, seconds: number): AudioBuffer {
  const b = ctx.createBuffer(1, Math.ceil(seconds * ctx.sampleRate), ctx.sampleRate);
  const d = b.getChannelData(0);
  for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
  return b;
}

function env(ctx: BaseAudioContext, at: number, attack: number, decay: number, peak = 1): GainNode {
  const g = ctx.createGain();
  g.gain.setValueAtTime(0.0001, at);
  g.gain.exponentialRampToValueAtTime(peak, at + attack);
  g.gain.exponentialRampToValueAtTime(0.0001, at + attack + decay);
  return g;
}

function tone(ctx: BaseAudioContext, out: AudioNode, type: OscillatorType, f0: number, f1: number, at: number, dur: number, peak = 0.6, attack = 0.005) {
  const o = ctx.createOscillator();
  o.type = type;
  o.frequency.setValueAtTime(f0, at);
  if (f1 !== f0) o.frequency.exponentialRampToValueAtTime(Math.max(1, f1), at + dur);
  const g = env(ctx, at, attack, dur, peak);
  o.connect(g).connect(out);
  o.start(at);
  o.stop(at + attack + dur + 0.05);
}

function noise(ctx: BaseAudioContext, out: AudioNode, at: number, dur: number, filter: BiquadFilterType, freq: number, peak = 0.5, q = 1) {
  const s = ctx.createBufferSource();
  s.buffer = noiseBuffer(ctx, dur + 0.1);
  const f = ctx.createBiquadFilter();
  f.type = filter;
  f.frequency.value = freq;
  f.Q.value = q;
  const g = env(ctx, at, 0.002, dur, peak);
  s.connect(f).connect(g).connect(out);
  s.start(at);
  s.stop(at + dur + 0.1);
}

const kick = (ctx: BaseAudioContext, out: AudioNode, at: number) => tone(ctx, out, 'sine', 150, 45, at, 0.32, 0.9);
const hat = (ctx: BaseAudioContext, out: AudioNode, at: number, v = 0.18) => noise(ctx, out, at, 0.05, 'highpass', 7000, v);
const snare = (ctx: BaseAudioContext, out: AudioNode, at: number) => {
  noise(ctx, out, at, 0.18, 'bandpass', 1800, 0.45, 0.7);
  tone(ctx, out, 'triangle', 220, 160, at, 0.1, 0.3);
};

const NOTE = (n: number) => 440 * Math.pow(2, (n - 69) / 12);

function pad(ctx: BaseAudioContext, out: AudioNode, notes: number[], at: number, dur: number, vol = 0.08) {
  const lp = ctx.createBiquadFilter();
  lp.type = 'lowpass';
  lp.frequency.value = 1400;
  const g = ctx.createGain();
  g.gain.setValueAtTime(0, at);
  g.gain.linearRampToValueAtTime(vol, at + dur * 0.35);
  g.gain.linearRampToValueAtTime(0, at + dur);
  lp.connect(g).connect(out);
  for (const n of notes) {
    for (const det of [-7, 7]) {
      const o = ctx.createOscillator();
      o.type = 'sawtooth';
      o.frequency.value = NOTE(n);
      o.detune.value = det;
      o.connect(lp);
      o.start(at);
      o.stop(at + dur + 0.05);
    }
  }
}

const BUILDERS: Record<string, { seconds: number; build: Build }> = {
  whoosh: {
    seconds: 1.3,
    build: (ctx, out) => {
      const s = ctx.createBufferSource();
      s.buffer = noiseBuffer(ctx, 1.3);
      const f = ctx.createBiquadFilter();
      f.type = 'bandpass';
      f.Q.value = 1.2;
      f.frequency.setValueAtTime(300, 0);
      f.frequency.exponentialRampToValueAtTime(3200, 0.6);
      f.frequency.exponentialRampToValueAtTime(400, 1.2);
      const g = ctx.createGain();
      g.gain.setValueAtTime(0, 0);
      g.gain.linearRampToValueAtTime(0.8, 0.55);
      g.gain.linearRampToValueAtTime(0, 1.25);
      s.connect(f).connect(g).connect(out);
      s.start(0);
    },
  },
  pop: { seconds: 0.3, build: (ctx, out) => tone(ctx, out, 'sine', 700, 120, 0, 0.14, 0.8) },
  ding: {
    seconds: 2.2,
    build: (ctx, out) => {
      [1, 2.76, 5.4].forEach((m, i) => tone(ctx, out, 'sine', 1046 * m, 1046 * m, 0, 2 - i * 0.5, 0.35 / (i + 1)));
    },
  },
  click: { seconds: 0.08, build: (ctx, out) => noise(ctx, out, 0, 0.025, 'highpass', 3000, 0.7) },
  beep: { seconds: 1, build: (ctx, out) => tone(ctx, out, 'sine', 1000, 1000, 0, 0.95, 0.35, 0.01) },
  riser: {
    seconds: 3,
    build: (ctx, out) => {
      const o = ctx.createOscillator();
      o.type = 'sawtooth';
      o.frequency.setValueAtTime(110, 0);
      o.frequency.exponentialRampToValueAtTime(1760, 2.9);
      const f = ctx.createBiquadFilter();
      f.type = 'lowpass';
      f.frequency.setValueAtTime(400, 0);
      f.frequency.exponentialRampToValueAtTime(8000, 2.9);
      const g = ctx.createGain();
      g.gain.setValueAtTime(0.0001, 0);
      g.gain.exponentialRampToValueAtTime(0.35, 2.85);
      g.gain.linearRampToValueAtTime(0, 3);
      o.connect(f).connect(g).connect(out);
      o.start(0);
      o.stop(3);
      noise(ctx, out, 0, 2.9, 'highpass', 4000, 0.08);
    },
  },
  boom: {
    seconds: 2,
    build: (ctx, out) => {
      tone(ctx, out, 'sine', 90, 30, 0, 1.8, 1);
      noise(ctx, out, 0, 0.6, 'lowpass', 300, 0.6);
    },
  },
  notify: {
    seconds: 0.9,
    build: (ctx, out) => {
      tone(ctx, out, 'sine', 880, 880, 0, 0.35, 0.4);
      tone(ctx, out, 'sine', 1320, 1320, 0.15, 0.6, 0.4);
    },
  },
  swipe: {
    seconds: 0.4,
    build: (ctx, out) => {
      const s = ctx.createBufferSource();
      s.buffer = noiseBuffer(ctx, 0.4);
      const f = ctx.createBiquadFilter();
      f.type = 'highpass';
      f.frequency.setValueAtTime(800, 0);
      f.frequency.exponentialRampToValueAtTime(6000, 0.3);
      const g = env(ctx, 0, 0.05, 0.3, 0.5);
      s.connect(f).connect(g).connect(out);
      s.start(0);
    },
  },
  beat: {
    seconds: 16,
    build: (ctx, out) => {
      const spb = 0.5; // 120 bpm
      const bass = [45, 45, 48, 43];
      for (let bar = 0; bar < 8; bar++) {
        for (let b = 0; b < 4; b++) {
          const at = (bar * 4 + b) * spb;
          kick(ctx, out, at);
          if (b % 2 === 1) snare(ctx, out, at);
          hat(ctx, out, at + spb / 2);
          hat(ctx, out, at, 0.08);
          tone(ctx, out, 'square', NOTE(bass[bar % 4]), NOTE(bass[bar % 4]), at + spb / 2, 0.2, 0.08);
        }
      }
      [[57, 60, 64], [57, 60, 64], [60, 64, 67], [55, 59, 62]].forEach((ch, i) => {
        pad(ctx, out, ch, i * 4, 4, 0.05);
        pad(ctx, out, ch, 8 + i * 2, 2, 0.05);
      });
    },
  },
  chill: {
    seconds: 16,
    build: (ctx, out) => {
      [[57, 60, 64, 67], [53, 57, 60, 64], [55, 59, 62, 65], [52, 55, 59, 62]].forEach((ch, i) => pad(ctx, out, ch, i * 4, 4.2, 0.07));
      for (let i = 0; i < 16; i++) {
        const n = [72, 76, 79, 74, 77, 81, 74, 79][i % 8];
        tone(ctx, out, 'triangle', NOTE(n), NOTE(n), i + 0.5, 0.8, 0.07, 0.02);
      }
    },
  },
  pulse: {
    seconds: 16,
    build: (ctx, out) => {
      const seq = [45, 57, 52, 57, 48, 60, 55, 60];
      for (let i = 0; i < 128; i++) {
        const at = i * 0.125;
        const n = seq[Math.floor(i / 2) % 8] + (Math.floor(i / 32) % 2 ? 3 : 0);
        tone(ctx, out, 'sawtooth', NOTE(n), NOTE(n), at, 0.1, 0.07);
        if (i % 4 === 0) kick(ctx, out, at);
        if (i % 8 === 4) snare(ctx, out, at);
      }
    },
  },
};

function encodeWav(buf: AudioBuffer): Blob {
  const ch = buf.numberOfChannels;
  const len = buf.length * ch * 2;
  const out = new DataView(new ArrayBuffer(44 + len));
  const str = (o: number, s: string) => [...s].forEach((c, i) => out.setUint8(o + i, c.charCodeAt(0)));
  str(0, 'RIFF');
  out.setUint32(4, 36 + len, true);
  str(8, 'WAVE');
  str(12, 'fmt ');
  out.setUint32(16, 16, true);
  out.setUint16(20, 1, true);
  out.setUint16(22, ch, true);
  out.setUint32(24, buf.sampleRate, true);
  out.setUint32(28, buf.sampleRate * ch * 2, true);
  out.setUint16(32, ch * 2, true);
  out.setUint16(34, 16, true);
  str(36, 'data');
  out.setUint32(40, len, true);
  const chans = Array.from({ length: ch }, (_, i) => buf.getChannelData(i));
  let o = 44;
  for (let i = 0; i < buf.length; i++) {
    for (let c = 0; c < ch; c++) {
      const s = Math.max(-1, Math.min(1, chans[c][i]));
      out.setInt16(o, s < 0 ? s * 0x8000 : s * 0x7fff, true);
      o += 2;
    }
  }
  return new Blob([out], { type: 'audio/wav' });
}

/** Returns the project's media item for a built-in sound, synthesising it on first use. */
export async function builtinSound(id: string): Promise<MediaItem | null> {
  for (const m of store.media.values()) if (m.builtin === id) return m;
  const def = BUILDERS[id];
  const meta = SOUNDS.find((s) => s.id === id);
  if (!def || !meta) return null;
  const ctx = new OfflineAudioContext(2, Math.ceil(def.seconds * SR), SR);
  const comp = ctx.createDynamicsCompressor();
  comp.connect(ctx.destination);
  def.build(ctx, comp);
  const buf = await ctx.startRendering();
  return createMedia(encodeWav(buf), `${meta.label}.wav`, { builtin: id, kind: 'audio' });
}
