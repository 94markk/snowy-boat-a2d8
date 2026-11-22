/// Royalty-free background music, composed and synthesized on the phone.
///
/// Every [MusicMood] is a short loop (a whole number of bars) rendered from
/// scratch by [synthesizeLoop]: no network and no bundled audio files. The
/// loops are cached as WAV files by [MusicLibrary.pathFor] and played on
/// repeat under a video montage.
library;

import 'dart:io';
import 'dart:isolate';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:path_provider/path_provider.dart';

enum MusicMood {
  kompa('Kompa', 'Groove konpa chaleureux, guitare et kata', 110, 8),
  afro('Afro', 'Afrobeats ensoleillé avec shaker et marimba', 104, 8),
  fete('Fête', 'Rythme festif qui donne envie de danser', 124, 12),
  chill('Chill', 'Lo-fi doux et posé, piano feutré', 84, 8),
  energie('Énergie', 'Rythme entraînant, idéal pour les promos', 128, 12),
  suspense('Suspense', 'Tension sourde, parfaite pour une révélation', 72, 6);

  const MusicMood(this.label, this.hint, this.bpm, this.bars);

  final String label;
  final String hint;

  /// Tempo in beats per minute.
  final int bpm;

  /// Length of the loop in 4/4 bars.
  final int bars;
}

abstract final class MusicLibrary {
  static final Map<MusicMood, Future<String>> _inFlight = {};

  /// Path of the cached WAV loop for [mood], synthesizing it on first use.
  static Future<String> pathFor(MusicMood mood) =>
      _inFlight[mood] ??= _ensure(mood).whenComplete(() => _inFlight.remove(mood));

  /// Exact duration of one pass of the loop for [mood].
  static Duration lengthOf(MusicMood mood) => Duration(microseconds: (mood.bars * 4 * 60000000 / mood.bpm).round());

  static Future<String> _ensure(MusicMood mood) async {
    final base = await getApplicationSupportDirectory();
    final dir = Directory('${base.path}/music');
    final file = File('${dir.path}/${mood.name}_v1.wav');
    if (await file.exists() && await file.length() == 44 + 2 * _loopSamples(mood.bpm, mood.bars, 44100)) {
      return file.path;
    }
    await dir.create(recursive: true);
    final bytes = await Isolate.run(() => synthesizeLoop(mood));
    final tmp = File('${file.path}.tmp');
    await tmp.writeAsBytes(bytes, flush: true);
    await tmp.rename(file.path);
    return file.path;
  }
}

int _loopSamples(int bpm, int bars, int sampleRate) => (bars * 4 * 60 * sampleRate / bpm).round();

/// Renders the loop for [mood] as a complete WAV file (PCM 16-bit, mono).
///
/// Pure and deterministic: the same mood and sample rate always give the same
/// bytes. The loop is a whole number of bars and wraps seamlessly: notes that
/// ring past the end continue at the start, and effects are computed in their
/// steady state.
Uint8List synthesizeLoop(MusicMood mood, {int sampleRate = 44100}) {
  final e = _Engine(sampleRate, mood.bpm, mood.bars, 0x5EED + 977 * mood.index);
  final mix = switch (mood) {
    MusicMood.kompa => _kompa(e),
    MusicMood.afro => _afro(e),
    MusicMood.fete => _fete(e),
    MusicMood.chill => _chill(e),
    MusicMood.energie => _energie(e),
    MusicMood.suspense => _suspense(e),
  };
  return _wav(_master(mix), sampleRate);
}

// ---------------------------------------------------------------------------
// Compositions. Positions are (bar, sixteenth step); pitches are MIDI notes.
// ---------------------------------------------------------------------------

/// Konpa in F major: steady kick, cinquillo "kata" on the bell, maracas,
/// bouncing bass and bright guitar stabs with a chiming arpeggio.
Float64List _kompa(_Engine e) {
  const prog = <(int, List<int>)>[
    (41, [65, 69, 72, 76]), // Fmaj7
    (38, [65, 69, 72, 74]), // Dm7
    (43, [65, 70, 74, 77]), // Gm7
    (48, [64, 67, 70, 72]), // C7
    (41, [65, 69, 72, 76]), // Fmaj7
    (38, [65, 69, 72, 74]), // Dm7
    (46, [65, 69, 70, 74]), // Bbmaj7
    (48, [64, 67, 70, 72]), // C7
  ];
  const bassLine = <(int, int, double)>[(0, 0, 2.6), (3, 0, 0.9), (6, 7, 1.8), (8, 12, 1.6), (10, 7, 1.6), (12, 0, 1.6), (14, -5, 1.6)];
  const kata = [0, 2, 3, 5, 6, 8, 10, 11, 13, 14];
  const arpSteps = [0, 3, 6, 8, 11, 14];
  const arpA = [0, 2, 4, 3, 1, 2];
  const arpB = [4, 3, 1, 2, 0, 1];
  for (var bar = 0; bar < 8; bar++) {
    final (root, v) = prog[bar];
    for (var b = 0; b < 4; b++) {
      e.kick(e.at(bar, b * 4), b.isEven ? 0.62 : 0.54, hi: 120, lo: 50, decay: 0.14, drive: 1.3);
    }
    e.rim(e.at(bar, 4), 0.2);
    e.rim(e.at(bar, 12), 0.25);
    for (final s in kata) {
      e.bell(e.at(bar, s), s % 8 == 0 ? 0.34 : (s.isOdd ? 0.22 : 0.18));
    }
    for (var s = 0; s < 16; s++) {
      e.hat(e.at(bar, s), s % 4 == 2 ? 0.13 : 0.07, decay: 0.028, hp: 4500, lp: 8500, attack: 0.006);
    }
    if (bar.isOdd) {
      e.tom(e.at(bar, 14), 0.24, 120);
      e.tom(e.at(bar, 15), 0.28, 98);
    }
    if (bar == 7) {
      e.tom(e.at(bar, 10), 0.22, 150);
      e.tom(e.at(bar, 12), 0.24, 135);
    }
    for (final (s, iv, l) in bassLine) {
      e.bass(e.at(bar, s), e.steps(l), root + iv, s == 0 ? 0.38 : 0.32, h2: 0.35, h3: 0.15, drive: 1.6);
    }
    for (final s in const [2, 6, 10, 14]) {
      for (var k = 0; k < v.length; k++) {
        e.pluck(e.at(bar, s) + k * e.secs(0.007), e.secs(0.16), v[k], s == 6 || s == 14 ? 0.32 : 0.26, bright: 0.8, verb: 0.12);
      }
    }
    final tones = [v[1], v[2], v[3], v[0] + 12, v[1] + 12];
    final contour = bar.isEven ? arpA : arpB;
    for (var k = 0; k < arpSteps.length; k++) {
      e.pluck(e.at(bar, arpSteps[k]), e.secs(0.5), tones[contour[k]], 0.32, bright: 0.65, verb: 0.2, echo: 0.6);
    }
    e.pad(e.at(bar, 0), e.steps(16) + e.secs(0.35), [for (final m in v) m - 12], 0.07, attack: 0.12, release: 0.35, cutoff: 1400);
  }
  return e.mixdown(verb: 0.3, room: 0.6, echoDelay: e.steps(3), echoFb: 0.3, echoGain: 0.8);
}

/// Afrobeats in A minor: syncopated kick, shaker, log drum bass and a
/// marimba riff over vi-IV-I-V.
Float64List _afro(_Engine e) {
  const prog = <(int, List<int>, List<int>)>[
    (45, [57, 60, 64, 67], [69, 72, 76, 79]), // Am7
    (41, [57, 60, 64, 65], [72, 76, 77, 81]), // Fmaj7
    (48, [55, 59, 60, 64], [72, 76, 79, 84]), // Cmaj7
    (43, [55, 59, 62, 64], [71, 74, 79, 83]), // G6
  ];
  const logLine = <(int, int, double)>[(0, 0, 2.6), (3, 0, 2.2), (6, 12, 1.8), (10, 7, 2.2), (13, 0, 2.6)];
  const kickA = [0, 6, 10];
  const kickB = [0, 7, 10, 13];
  const rimA = [3, 11];
  const rimB = [3, 6, 14];
  const mSteps = [0, 3, 6, 10, 12, 14];
  const mA = [3, 2, 0, 1, 2, 1];
  const mB = [2, 3, 1, 0, 1, 2];
  for (var bar = 0; bar < 8; bar++) {
    final (root, padV, top) = prog[bar % 4];
    for (final s in bar.isEven ? kickA : kickB) {
      e.kick(e.at(bar, s), s == 0 ? 0.75 : 0.55, hi: 140, lo: 48, decay: 0.16, drive: 1.5);
    }
    e.clap(e.at(bar, 4), 0.36, tail: 0.09);
    e.clap(e.at(bar, 12), 0.42, tail: 0.1);
    for (final s in bar.isEven ? rimA : rimB) {
      e.rim(e.at(bar, s), 0.22);
    }
    const shake = [0.1, 0.07, 0.2, 0.09];
    for (var s = 0; s < 16; s++) {
      e.hat(e.at(bar, s), shake[s % 4], decay: 0.04, hp: 5000, lp: 9000, attack: 0.008);
    }
    if (bar % 4 == 3) {
      e.tom(e.at(bar, 13), 0.22, 210);
      e.tom(e.at(bar, 14), 0.25, 180);
      e.tom(e.at(bar, 15), 0.28, 160);
    }
    for (final (s, iv, l) in logLine) {
      e.logDrum(e.at(bar, s), e.steps(l), root + iv, s == 0 ? 0.45 : 0.38);
    }
    final contour = bar < 4 ? mA : mB;
    for (var k = 0; k < mSteps.length; k++) {
      e.marimba(e.at(bar, mSteps[k]), top[contour[k]], k == 0 ? 0.26 : 0.21);
    }
    e.pad(e.at(bar, 0), e.steps(16) + e.secs(0.4), padV, 0.07, attack: 0.25, release: 0.4, cutoff: 1500);
  }
  return e.mixdown(verb: 0.25, room: 0.65, echoDelay: e.steps(3), echoFb: 0.28, echoGain: 0.75);
}

/// Party house in G major: four-on-the-floor, offbeat bass and hats, organ
/// stabs that pump with the kick and a hook on the last two cycles.
Float64List _fete(_Engine e) {
  const prog = <(int, List<int>)>[
    (43, [59, 62, 67, 71]), // G
    (38, [57, 62, 66, 69]), // D
    (40, [59, 64, 67, 71]), // Em
    (36, [60, 64, 67, 72]), // C
  ];
  const stabs = <(int, double)>[(0, 1.6), (3, 1.6), (6, 2.6), (10, 1.6), (13, 1.8)];
  const hook = <List<(int, int, double)>>[
    [(0, 71, 2), (3, 74, 2), (6, 79, 3), (10, 78, 2), (12, 74, 3)],
    [(0, 69, 2), (3, 74, 2), (6, 78, 3), (10, 76, 2), (12, 74, 3)],
    [(0, 71, 2), (3, 76, 2), (6, 79, 3), (10, 78, 2), (12, 76, 3)],
    [(0, 72, 2), (3, 76, 2), (6, 79, 2), (8, 81, 2), (10, 79, 2), (12, 76, 2), (14, 74, 2)],
  ];
  for (var bar = 0; bar < 12; bar++) {
    final (root, v) = prog[bar % 4];
    for (var b = 0; b < 4; b++) {
      e.kick(e.at(bar, b * 4), 0.7, hi: 160, lo: 50, decay: 0.18, drive: 1.8, click: 0.2);
      if (bar < 11 || b < 3) e.hat(e.at(bar, b * 4 + 2), 0.3, decay: 0.11, hp: 6500, lp: 10000);
    }
    e.clap(e.at(bar, 4), 0.6);
    e.clap(e.at(bar, 12), 0.6);
    for (var s = 1; s < 16; s += 2) {
      e.hat(e.at(bar, s), 0.25, decay: 0.025, hp: 7000, lp: 10500);
    }
    if (bar == 11) {
      // Fill, then a short gap so the loop drops cleanly back onto bar 1.
      for (var s = 12; s < 15; s++) {
        e.snare(e.at(bar, s), 0.26 + 0.1 * (s - 12), decay: 0.07);
      }
    }
    if (bar % 4 == 0) e.crash(e.at(bar, 0), bar == 0 ? 0.22 : 0.15);
    for (final s in const [2, 6, 10, 14]) {
      final up = s == 14 && bar.isOdd ? 12 : 0;
      e.bass(e.at(bar, s), e.steps(1.6), root + up, 0.5, h2: 0.4, h3: 0.2, drive: 1.8, decay: 0.12, sustain: 0.5);
    }
    for (final (s, l) in stabs) {
      e.pad(e.at(bar, s), e.steps(l), v, s == 0 ? 0.4 : 0.33, attack: 0.004, release: 0.06, cutoff: 2600, voices: 2, detune: 10, verb: 0.3);
    }
    e.pad(e.at(bar, 0), e.steps(16) + e.secs(0.2), [for (final m in v) m - 12], 0.09, attack: 0.08, release: 0.2, cutoff: 1100);
    if (bar >= 4) {
      for (final (s, m, l) in hook[bar % 4]) {
        e.synth(e.at(bar, s), e.steps(l), m, bar >= 8 ? 0.5 : 0.4, cut0: 4200, cut1: 900, fdecay: 0.12, decay: 0.35, voices: 2, detune: 7, verb: 0.3, echo: 0.5);
      }
    }
  }
  return e.mixdown(verb: 0.14, room: 0.7, echoDelay: e.steps(3), echoFb: 0.3, echoGain: 0.6, duck: 0.55);
}

/// Lo-fi in E flat: swung soft drums, warm electric piano over
/// IV-iii-ii-V, round bass, vinyl crackle and a sparse melody.
Float64List _chill(_Engine e) {
  e.swing = 0.22;
  const prog = <(int, List<int>)>[
    (44, [56, 60, 63, 67]), // Abmaj7
    (43, [55, 58, 62, 65]), // Gm7
    (41, [55, 56, 60, 63]), // Fm9
    (46, [56, 62, 67, 72]), // Bb13
  ];
  const melody = <List<(int, int, double)>>[
    [(2, 75, 2), (4, 79, 5), (10, 77, 2), (12, 75, 4)],
    [(2, 74, 2), (4, 77, 6), (12, 74, 2), (14, 72, 2)],
    [(0, 72, 4), (6, 75, 2), (8, 79, 6)],
    [(4, 74, 3), (8, 72, 3), (12, 67, 2.5)],
  ];
  const hats = [0.3, 0.13, 0.21, 0.13];
  for (var bar = 0; bar < 8; bar++) {
    final (root, v) = prog[bar % 4];
    e.kick(e.at(bar, 0), 0.62, hi: 105, lo: 46, decay: 0.18, drive: 1.2, click: 0.05);
    e.kick(e.at(bar, 7), 0.4, hi: 105, lo: 46, decay: 0.16, drive: 1.2, click: 0.05);
    e.kick(e.at(bar, 10), 0.54, hi: 105, lo: 46, decay: 0.18, drive: 1.2, click: 0.05);
    e.snare(e.at(bar, 4), 0.58, tone: 180, decay: 0.12, lp: 5000, verb: 0.35);
    e.snare(e.at(bar, 12), 0.62, tone: 180, decay: 0.12, lp: 5000, verb: 0.35);
    for (var s = 0; s < 16; s++) {
      // Eighths with swung sixteenth ghost notes in between.
      e.hat(e.at(bar, s), s.isEven ? hats[(s ~/ 2) % 4] : 0.07, decay: s.isEven ? 0.03 : 0.022, hp: 5500, lp: 7500);
    }
    e.bass(e.at(bar, 0), e.steps(6.5), root, 0.32, h2: 0.15, h3: 0.05, drive: 1.1, decay: 0.4, sustain: 0.75);
    e.bass(e.at(bar, 10), e.steps(3.6), root + 7, 0.27, h2: 0.15, h3: 0.05, drive: 1.1, decay: 0.4, sustain: 0.75);
    e.bass(e.at(bar, 14), e.steps(1.8), root + 12, 0.26, h2: 0.15, h3: 0.05, drive: 1.1, decay: 0.3, sustain: 0.7);
    for (var k = 0; k < v.length; k++) {
      e.ep(e.at(bar, 0) + k * e.secs(0.012), e.steps(9.5), v[k], 0.2, index: 1.1, verb: 0.3);
    }
    final reHit = bar.isEven ? 10 : 7;
    for (var k = 0; k < v.length; k++) {
      e.ep(e.at(bar, reHit) + k * e.secs(0.01), e.steps(16 - reHit.toDouble()), v[k], 0.12, index: 0.9, verb: 0.3);
    }
    if (bar >= 4) {
      for (final (s, m, l) in melody[bar % 4]) {
        e.ep(e.at(bar, s), e.steps(l + 1), m, 0.28, index: 0.7, decay: 1.2, verb: 0.4, echo: 0.5);
      }
    }
  }
  // Vinyl crackle: sparse, quiet, soft clicks.
  final crackles = (e.n / e.sr * 3).round();
  for (var i = 0; i < crackles; i++) {
    final pos = (e.rand() * e.n).floor();
    e.hat(pos, 0.06 + 0.1 * e.rand(), decay: 0.0006, hp: 1200, lp: 5000, attack: 0.0002);
  }
  return e.mixdown(verb: 0.12, room: 0.7, damp: 0.6, echoDelay: e.steps(3), echoFb: 0.32, echoLp: 2500, echoGain: 0.65);
}

/// Driving promo track in B minor: punchy four-on-the-floor, rolling bass,
/// pumping supersaw chords, a 16th arpeggio and a riser into the loop point.
Float64List _energie(_Engine e) {
  const prog = <(int, List<int>)>[
    (47, [59, 62, 66, 71]), // Bm
    (43, [59, 62, 67, 71]), // G
    (50, [57, 62, 66, 69]), // D
    (45, [57, 61, 64, 69]), // A
  ];
  const arp = [0, 1, 2, 3, 2, 1, 2, 3];
  for (var bar = 0; bar < 12; bar++) {
    final (root, v) = prog[bar % 4];
    for (var b = 0; b < 4; b++) {
      e.kick(e.at(bar, b * 4), 0.72, hi: 175, lo: 52, decay: 0.17, drive: 2.0, click: 0.25);
      if (bar < 11 || b < 3) e.hat(e.at(bar, b * 4 + 2), 0.32, decay: 0.09, hp: 6500, lp: 10000);
      for (final s in const [1, 2, 3]) {
        e.synth(e.at(bar, b * 4 + s), e.steps(0.85), root + (s == 3 && b.isOdd ? 12 : 0), 0.36,
            cut0: 1500, cut1: 380, fdecay: 0.05, decay: 0.12, sustain: 0.4, voices: 1, sub: 0.7);
      }
    }
    e.clap(e.at(bar, 4), 0.5);
    e.clap(e.at(bar, 12), 0.5);
    e.snare(e.at(bar, 4), 0.3, decay: 0.1);
    e.snare(e.at(bar, 12), 0.3, decay: 0.1);
    for (var s = 0; s < 16; s++) {
      if (s % 4 != 2) e.hat(e.at(bar, s), s.isOdd ? 0.18 : 0.25, decay: 0.022, hp: 7000, lp: 10500);
    }
    if (bar % 4 == 0) e.crash(e.at(bar, 0), bar == 0 ? 0.25 : 0.16);
    e.pad(e.at(bar, 0), e.steps(16), v, 0.25, attack: 0.006, release: 0.12, cutoff: 2400, voices: 3, detune: 12, verb: 0.25);
    final lead = [v[1] + 12, v[2] + 12, v[3] + 12, v[1] + 24];
    for (var s = 0; s < 16; s++) {
      e.synth(e.at(bar, s), e.steps(0.9), lead[arp[s % 8]], bar < 4 ? 0.27 : 0.4,
          cut0: bar < 4 ? 2200 : 3800, cut1: 700, fdecay: 0.06, decay: 0.14, voices: 1, verb: 0.2, echo: 0.4);
    }
    if (bar == 11) {
      for (var s = 8; s < 15; s++) {
        e.snare(e.at(bar, s), 0.15 + 0.05 * (s - 8), decay: 0.06);
      }
      e.riser(e.at(bar, 0), e.steps(16), 0.7, f0: 500, f1: 8000);
    }
  }
  return e.mixdown(verb: 0.16, room: 0.7, echoDelay: e.steps(3), echoFb: 0.3, echoGain: 0.6, duck: 0.6);
}

/// Suspense in D minor: low eighth-note pulse, tension pad with a pedal E,
/// clock ticks, distant pings and a swell back into the start.
Float64List _suspense(_Engine e) {
  const prog = <(int, List<int>)>[
    (38, [50, 57, 64, 65]), // Dm(add9), two bars
    (38, [50, 57, 64, 65]),
    (34, [53, 57, 62, 64]), // Bbmaj7#11, two bars
    (34, [53, 57, 62, 64]),
    (43, [55, 58, 62, 64]), // Gm6/9
    (45, [55, 61, 64, 70]), // A7b9
  ];
  const pings = <List<(int, int)>>[
    [(0, 81), (6, 76), (12, 77)],
    [(8, 74)],
    [(0, 81), (6, 76), (12, 74)],
    [(8, 72)],
    [(0, 79), (6, 74)],
    [(0, 82), (6, 73), (12, 76)],
  ];
  for (var bar = 0; bar < 6; bar++) {
    final (root, _) = prog[bar];
    for (var s = 0; s < 16; s += 2) {
      e.synth(e.at(bar, s), e.steps(1.8), root, s % 4 == 0 ? 0.3 : 0.2,
          cut0: 900, cut1: 320, fdecay: 0.08, decay: 0.2, sustain: 0.55, voices: 1, sub: 0.25);
      e.hat(e.at(bar, s), s % 4 == 0 ? 0.15 : 0.1, decay: 0.006, hp: s % 4 == 0 ? 3200 : 2400, lp: 7000, verb: 0.3);
    }
    if (bar.isEven) {
      final (_, v) = prog[bar];
      e.pad(e.at(bar, 0), e.steps(bar == 4 ? 16 : 32) + e.secs(1.4), v, 0.12, attack: 1.0, release: 1.4, cutoff: 1100, voices: 2, detune: 9, verb: 0.5);
    }
    if (bar == 5) {
      e.pad(e.at(bar, 0), e.steps(16) + e.secs(1.4), prog[5].$2, 0.18, attack: 0.8, release: 1.4, cutoff: 1300, voices: 2, detune: 9, verb: 0.5);
      e.riser(e.at(bar, 4), e.steps(12), 0.3, f0: 300, f1: 3500);
    }
    for (final (s, m) in pings[bar]) {
      e.ping(e.at(bar, s), m, 0.12);
    }
  }
  e.kick(e.at(0, 0), 0.45, hi: 90, lo: 34, sweep: 0.12, decay: 0.9, drive: 1.3, click: 0);
  e.kick(e.at(3, 0), 0.35, hi: 80, lo: 34, sweep: 0.1, decay: 0.7, drive: 1.2, click: 0);
  return e.mixdown(verb: 0.15, room: 0.85, damp: 0.5, echoDelay: e.steps(3), echoFb: 0.4, echoLp: 2800, echoGain: 0.75);
}

// ---------------------------------------------------------------------------
// Synthesis engine.
// ---------------------------------------------------------------------------

const double _tau = 2 * math.pi;
const int _sineSize = 4096;
const int _tableSize = 2048;

final Float64List _sineTable = () {
  final t = Float64List(_sineSize + 2);
  for (var i = 0; i < t.length; i++) {
    t[i] = math.sin(_tau * i / _sineSize);
  }
  return t;
}();

double _hz(num midi) => 440.0 * math.pow(2, (midi - 69) / 12);

/// Padé approximation of tanh, exact at the clipping points ±3.
double _tanh(double x) {
  if (x >= 3) return 1;
  if (x <= -3) return -1;
  final x2 = x * x;
  return x * (27 + x2) / (27 + 9 * x2);
}

final class _Engine {
  _Engine(this.sr, int bpm, int bars, int seed)
      : spb = sr * 60.0 / bpm,
        n = _loopSamples(bpm, bars, sr),
        _rng = (seed * 2654435761 + 12345) & 0xFFFFFFFF | 1;

  final int sr;

  /// Samples per beat.
  final double spb;

  /// Loop length in samples.
  final int n;

  /// Delay of odd sixteenths, as a fraction of a sixteenth.
  double swing = 0;

  int _rng;

  late final Float64List drums = Float64List(n);
  late final Float64List music = Float64List(n);
  late final Float64List verbSend = Float64List(n);
  late final Float64List echoSend = Float64List(n);

  /// Band-limited sawtooth: no aliasing for notes up to ~1.2 kHz (D6).
  late final Float64List _saw = _harmonicTable(math.max(4, math.min(20, (0.45 * sr / 1200).floor())), odd: false);

  /// Band-limited square (odd harmonics), for the bell.
  late final Float64List _square = _harmonicTable(9, odd: true);

  Float64List _tmp = Float64List(0);

  Float64List _harmonicTable(int harmonics, {required bool odd}) {
    final t = Float64List(_tableSize + 2);
    var peak = 0.0;
    for (var i = 0; i < t.length; i++) {
      var s = 0.0;
      for (var k = 1; k <= harmonics; k += odd ? 2 : 1) {
        final rolloff = 1 / (1 + math.pow(k / harmonics, 2) * 2);
        s += math.sin(_tau * k * i / _tableSize) / k * rolloff;
      }
      t[i] = s;
      peak = math.max(peak, s.abs());
    }
    for (var i = 0; i < t.length; i++) {
      t[i] /= peak;
    }
    return t;
  }

  double rand() => (_next() & 0xFFFFFF) / 16777216.0;

  int _next() {
    var x = _rng;
    x ^= (x << 13) & 0xFFFFFFFF;
    x ^= x >> 17;
    x ^= (x << 5) & 0xFFFFFFFF;
    return _rng = x;
  }

  double _noise() => _next() * (1.0 / 2147483648.0) - 1.0;

  /// sin(2π·ph) for a phase in cycles, ph >= 0.
  static double _sin(double ph) {
    final x = ph * _sineSize;
    final i = x.toInt();
    final j = i & (_sineSize - 1);
    final t = _sineTable;
    final a = t[j];
    return a + (t[j + 1] - a) * (x - i);
  }

  /// One cycle of wavetable [t] at phase [ph] (in cycles, ph >= 0).
  static double _wave(Float64List t, double ph) {
    final x = ph * _tableSize;
    final i = x.toInt();
    final j = i & (_tableSize - 1);
    final a = t[j];
    return a + (t[j + 1] - a) * (x - i);
  }

  /// Sample position of a sixteenth [step] in [bar], wrapped into the loop.
  int at(num bar, num step) {
    var s = step.toDouble();
    if (swing != 0 && step is int && step.isOdd) s += swing;
    return ((bar * 16 + s) * spb / 4).round() % n;
  }

  int steps(num sixteenths) => (sixteenths * spb / 4).round();

  int secs(double seconds) => (seconds * sr).round();

  /// One-pole low-pass coefficient for a cutoff in Hz.
  double _a(double hz) => 1 - math.exp(-_tau * math.min(hz, sr * 0.45) / sr);

  /// Per-sample multiplier of an exponential decay with time constant [seconds].
  double _k(double seconds) => math.exp(-1 / (seconds * sr));

  Float64List _buf(int len) {
    if (_tmp.length < len) _tmp = Float64List(len + sr);
    return _tmp;
  }

  /// Applies the note's attack and release ramps to the scratch buffer, then
  /// mixes it into [bus] (and the effect sends) at [start], wrapping around.
  void _emit(Float64List bus, int start, int len, double g, {double verb = 0, double echo = 0, double attack = 0.001, double release = 0.004}) {
    final b = _tmp;
    final att = math.min(len ~/ 2, math.max(1, secs(attack)));
    for (var i = 0; i < att; i++) {
      b[i] *= i / att;
    }
    final rel = math.min(len - att, math.max(1, secs(release)));
    for (var i = 0; i < rel; i++) {
      b[len - 1 - i] *= i / rel;
    }
    final s = start % n;
    _addTo(bus, s, len, g);
    if (verb > 0) _addTo(verbSend, s, len, g * verb);
    if (echo > 0) _addTo(echoSend, s, len, g * echo);
  }

  void _addTo(Float64List bus, int start, int len, double g) {
    final b = _tmp;
    var j = start;
    var i = 0;
    while (i < len) {
      final m = math.min(len - i, n - j);
      for (var k = 0; k < m; k++) {
        bus[j + k] += b[i + k] * g;
      }
      i += m;
      j = 0;
    }
  }

  // --- Drums -------------------------------------------------------------

  /// Sine kick with a pitch drop, a filtered-noise click and soft saturation.
  void kick(int t, double g, {double hi = 150, double lo = 50, double sweep = 0.035, double decay = 0.28, double drive = 1.6, double click = 0.15}) {
    final len = secs(decay * 4.6 + 0.01);
    final b = _buf(len);
    final kp = _k(sweep), ka = _k(decay), kc = _k(0.0025), ac = _a(5000);
    final norm = 1 / _tanh(drive), inv = 1.0 / sr;
    var ph = 0.0, pe = 1.0, ae = 1.0, ce = 1.0, c = 0.0;
    for (var i = 0; i < len; i++) {
      ph += (lo + (hi - lo) * pe) * inv;
      pe *= kp;
      c += ac * (_noise() - c);
      b[i] = _tanh((_sin(ph) * ae + c * ce * click) * drive) * norm;
      ae *= ka;
      ce *= kc;
    }
    _emit(drums, t, len, g, attack: 0.0015, release: 0.01);
  }

  /// Noise percussion: band-limited between [hp] and [lp] (two poles each
  /// side, so it stays soft), with an exponential decay.
  void hat(int t, double g, {double decay = 0.035, double hp = 7000, double lp = 10000, double attack = 0.0008, double verb = 0}) {
    final len = secs(attack + decay * 5.5) + 2;
    final b = _buf(len);
    final ah = _a(hp), al = _a(lp), k = _k(decay);
    final att = math.max(1, secs(attack));
    var h1 = 0.0, h2 = 0.0, l1 = 0.0, l2 = 0.0, env = 1.0;
    for (var i = 0; i < len; i++) {
      var x = _noise();
      h1 += ah * (x - h1);
      x -= h1;
      h2 += ah * (x - h2);
      x -= h2;
      l1 += al * (x - l1);
      l2 += al * (l1 - l2);
      double v;
      if (i < att) {
        final r = i / att;
        v = r * r;
      } else {
        v = env;
        env *= k;
      }
      b[i] = l2 * v * 6;
    }
    _emit(drums, t, len, g, verb: verb, attack: 0.0002, release: 0.002);
  }

  /// Hand clap: three quick band-passed noise bursts and a short tail.
  void clap(int t, double g, {double tail = 0.11, double hz = 1300, double verb = 0.25}) {
    final len = secs(0.03 + tail * 5);
    final b = _buf(len);
    final f = 2 * math.sin(math.pi * hz / sr);
    const q = 0.8;
    final o1 = secs(0.009), o2 = secs(0.018), o3 = secs(0.027);
    final kb = _k(0.004), kt = _k(tail), asm = _a(1200), al = _a(6000);
    var low = 0.0, band = 0.0, e1 = 1.0, e2 = 0.0, e3 = 0.0, et = 0.0, sm = 0.0, l = 0.0, l2 = 0.0;
    for (var i = 0; i < len; i++) {
      if (i == o1) e2 = 1;
      if (i == o2) e3 = 1;
      if (i == o3) et = 0.8;
      sm += asm * (e1 + e2 + e3 + et - sm);
      e1 *= kb;
      e2 *= kb;
      e3 *= kb;
      et *= kt;
      final high = _noise() - low - q * band;
      band += f * high;
      low += f * band;
      l += al * (band - l);
      l2 += al * (l - l2);
      b[i] = 5.5 * l2 * sm;
    }
    _emit(drums, t, len, g, verb: verb);
  }

  /// Snare: a pitched body plus filtered noise.
  void snare(int t, double g, {double tone = 190, double decay = 0.13, double lp = 7000, double verb = 0.2}) {
    final len = secs(decay * 5.5);
    final b = _buf(len);
    final kp = _k(0.015), kt = _k(0.06), kn = _k(decay), ah = _a(1500), al = _a(lp), inv = 1.0 / sr;
    var ph = 0.0, pe = 1.0, et = 1.0, en = 1.0, h = 0.0, l1 = 0.0, l2 = 0.0, l3 = 0.0;
    for (var i = 0; i < len; i++) {
      ph += tone * (1 + 0.5 * pe) * inv;
      pe *= kp;
      var x = _noise();
      h += ah * (x - h);
      x -= h;
      l1 += al * (x - l1);
      l2 += al * (l1 - l2);
      l3 += al * (l2 - l3);
      b[i] = 0.55 * _sin(ph) * et + 2.8 * l3 * en;
      et *= kt;
      en *= kn;
    }
    _emit(drums, t, len, g, verb: verb);
  }

  /// Rim click: two short tones and a tick of noise.
  void rim(int t, double g, {double verb = 0.12}) {
    final len = secs(0.07);
    final b = _buf(len);
    final k = _k(0.011), kn = _k(0.003), al = _a(6000), inv = 1.0 / sr;
    var e = 1.0, en = 1.0, l = 0.0;
    for (var i = 0; i < len; i++) {
      final tt = i * inv;
      l += al * (_noise() - l);
      b[i] = 3 * ((0.55 * _sin(1720 * tt) + 0.45 * _sin(830 * tt)) * e + 0.5 * l * en);
      e *= k;
      en *= kn;
    }
    _emit(drums, t, len, g, verb: verb);
  }

  /// Tom or conga: a sine falling onto [hz].
  void tom(int t, double g, double hz, {double decay = 0.2, double verb = 0.15}) {
    final len = secs(decay * 5);
    final b = _buf(len);
    final kp = _k(0.03), ka = _k(decay), inv = 1.0 / sr;
    var ph = 0.0, pe = 1.0, e = 1.0;
    for (var i = 0; i < len; i++) {
      ph += hz * (1 + 0.6 * pe) * inv;
      pe *= kp;
      final s = _sin(ph);
      b[i] = _tanh(1.4 * (s + 0.15 * _sin(2 * ph) * pe) * e);
      e *= ka;
    }
    _emit(drums, t, len, g, verb: verb, attack: 0.002);
  }

  /// Cowbell-style bell for the kata: two square tones, softened.
  void bell(int t, double g, {double f1 = 560, double f2 = 835, double decay = 0.08, double lp = 3600}) {
    final len = secs(decay * 5.5);
    final b = _buf(len);
    final sq = _square;
    final i1 = f1 / sr, i2 = f2 / sr, k1 = _k(0.012), k2 = _k(decay), ah = _a(400), al = _a(lp);
    var p1 = 0.0, p2 = 0.0, e1 = 0.6, e2 = 0.4, h = 0.0, l1 = 0.0, l2 = 0.0;
    for (var i = 0; i < len; i++) {
      var x = _wave(sq, p1) + _wave(sq, p2);
      p1 += i1;
      p2 += i2;
      h += ah * (x - h);
      x -= h;
      l1 += al * (x - l1);
      l2 += al * (l1 - l2);
      b[i] = 0.6 * l2 * (e1 + e2);
      e1 *= k1;
      e2 *= k2;
    }
    _emit(drums, t, len, g, verb: 0.1, attack: 0.0007);
  }

  /// Crash cymbal: long, gently filtered noise.
  void crash(int t, double g, {double decay = 1.1}) {
    final len = secs(decay * 5);
    final b = _buf(len);
    final ah = _a(3500), al = _a(9000), k = _k(decay);
    var h1 = 0.0, h2 = 0.0, l1 = 0.0, l2 = 0.0, e = 1.0;
    for (var i = 0; i < len; i++) {
      var x = _noise();
      h1 += ah * (x - h1);
      x -= h1;
      h2 += ah * (x - h2);
      x -= h2;
      l1 += al * (x - l1);
      l2 += al * (l1 - l2);
      b[i] = 2.2 * l2 * e;
      e *= k;
    }
    _emit(drums, t, len, g, verb: 0.25, attack: 0.002, release: 0.05);
  }

  /// Filtered noise swelling up over [len], faded out before it ends.
  void riser(int t, int len, double g, {double f0 = 400, double f1 = 7000}) {
    final b = _buf(len);
    final r = math.pow(f1 / f0, 1 / len).toDouble();
    var c = f0, l1 = 0.0, l2 = 0.0, h = 0.0;
    for (var i = 0; i < len; i++) {
      final w = _tau * c / sr, a = w / (1 + w), ah = a * 0.4;
      c *= r;
      final x = _noise();
      l1 += a * (x - l1);
      l2 += a * (l1 - l2);
      h += ah * (l2 - h);
      final p = i / len;
      b[i] = 2.0 * (l2 - h) * p * p;
    }
    _emit(music, t, len, g, verb: 0.4, attack: 0.01, release: 0.09);
  }

  // --- Pitched voices ------------------------------------------------------

  /// Warm bass: sine with a few harmonics, saturated so it still speaks on
  /// small phone speakers.
  void bass(int t, int len, int midi, double g, {double h2 = 0.3, double h3 = 0.12, double drive = 1.5, double decay = 0.25, double sustain = 0.7}) {
    final b = _buf(len);
    final inc = _hz(midi) / sr, k = _k(decay), norm = 1 / _tanh(drive);
    var ph = 0.0, e = 1.0;
    for (var i = 0; i < len; i++) {
      final y = _sin(ph) + h2 * _sin(2 * ph) + h3 * _sin(3 * ph);
      ph += inc;
      if (ph >= 1) ph -= 1;
      b[i] = _tanh(y * drive * (sustain + (1 - sustain) * e)) * norm;
      e *= k;
    }
    _emit(music, t, len, g, attack: 0.005, release: 0.03);
  }

  /// Log drum: a woody, pitch-dropping sine as in amapiano and afrobeats.
  void logDrum(int t, int len, int midi, double g) {
    final b = _buf(len);
    final f = _hz(midi), inv = 1.0 / sr;
    final kp = _k(0.014), ka = _k(0.3), k2 = _k(0.07);
    const drive = 2.0;
    final norm = 1 / _tanh(drive);
    var ph = 0.0, pe = 1.0, e = 1.0, e2 = 1.0;
    for (var i = 0; i < len; i++) {
      ph += f * (1 + 0.7 * pe) * inv;
      pe *= kp;
      final y = _sin(ph) + 0.35 * _sin(2 * ph) * e2;
      b[i] = _tanh(y * drive * e) * norm;
      e *= ka;
      e2 *= k2;
    }
    _emit(music, t, len, g, attack: 0.0015, release: 0.04);
  }

  /// Plucked string (Karplus-Strong with fractional tuning): guitar stabs
  /// and chiming arpeggios.
  void pluck(int t, int len, int midi, double g, {double bright = 0.6, double damp = 0.996, double verb = 0.15, double echo = 0}) {
    final b = _buf(len);
    final period = sr / _hz(midi);
    final size = math.max(2, (period - 0.6).floor());
    final d = period - 0.5 - size;
    final c = (1 - d) / (1 + d);
    final line = Float64List(size);
    final a = _a(900 + 5000 * bright);
    var lp = 0.0, lp2 = 0.0, mean = 0.0;
    for (var i = 0; i < size; i++) {
      lp += a * (_noise() - lp);
      lp2 += a * (lp - lp2);
      line[i] = lp2;
      mean += lp2;
    }
    mean /= size;
    // Pluck position: a comb on the excitation thins out the harmonics, as
    // when a string is plucked part-way along instead of at its very end.
    final pick = math.max(1, (size * 0.17).round());
    final shaped = Float64List(size);
    var energy = 0.0;
    for (var i = 0; i < size; i++) {
      shaped[i] = (line[i] - mean) - 0.8 * (line[(i + pick) % size] - mean);
      energy += shaped[i] * shaped[i];
    }
    final norm = energy > 0 ? 0.6 / math.sqrt(energy / size) : 0.0;
    for (var i = 0; i < size; i++) {
      line[i] = shaped[i] * norm;
    }
    final tone = _a(2500 + 5000 * bright);
    var p = 0, prev = 0.0, apx = 0.0, apy = 0.0, out = 0.0;
    for (var i = 0; i < len; i++) {
      final cur = line[p];
      out += tone * (cur - out);
      b[i] = out;
      final avg = 0.5 * (cur + prev);
      prev = cur;
      final ap = c * avg + apx - c * apy;
      apx = avg;
      apy = ap;
      line[p] = ap * damp;
      if (++p == size) p = 0;
    }
    _emit(music, t, len, g, verb: verb, echo: echo, attack: 0.0015, release: 0.03);
  }

  /// Electric piano: two-operator FM with a decaying index and tremolo.
  void ep(int t, int len, int midi, double g, {double index = 1.2, double decay = 1.6, double verb = 0.2, double echo = 0}) {
    final b = _buf(len);
    final f = _hz(midi), inc = f / sr;
    final ka = _k(decay * math.pow(262 / f, 0.4)), ki = _k(0.3), trem = 4.6 / sr;
    final depth = index / _tau;
    var pc = 0.0, pm = 0.0, pt = 0.0, e = 1.0, ei = 1.0;
    for (var i = 0; i < len; i++) {
      final mod = _sin(pm) * depth * (0.3 + 0.7 * ei);
      final y = _sin(pc + mod + 1) + 0.12 * _sin(2 * pc);
      b[i] = y * e * (1 + 0.08 * _sin(pt));
      pc += inc;
      pm += inc;
      pt += trem;
      if (pc >= 1) pc -= 1;
      if (pm >= 1) pm -= 1;
      e *= ka;
      ei *= ki;
    }
    _emit(music, t, len, g, verb: verb, echo: echo, attack: 0.003, release: 0.08);
  }

  /// Marimba: a sine bar with its two-octave and tenth overtones.
  void marimba(int t, int midi, double g, {double decay = 0.4, double verb = 0.22, double echo = 0.25}) {
    final f = _hz(midi), inc = f / sr;
    final dec = decay * math.pow(440 / f, 0.3);
    final len = secs(dec * 5);
    final b = _buf(len);
    final ka = _k(dec), k4 = _k(0.045), k10 = _k(0.012), kc = _k(0.002), al = _a(3000);
    final h10 = f * 10 < sr * 0.45 ? 0.12 : 0.0;
    // The overtones and the mallet click die out quickly: only compute them
    // while they are audible.
    final bright = math.min(len, secs(0.045 * 8));
    var ph = 0.0, e = 1.0, e4 = 1.0, e10 = 1.0, ec = 1.0, l = 0.0;
    for (var i = 0; i < bright; i++) {
      l += al * (_noise() - l);
      b[i] = _sin(ph) * e + 0.45 * _sin(4 * ph) * e4 + h10 * _sin(10 * ph) * e10 + 0.3 * l * ec;
      ph += inc;
      if (ph >= 1) ph -= 1;
      e *= ka;
      e4 *= k4;
      e10 *= k10;
      ec *= kc;
    }
    for (var i = bright; i < len; i++) {
      b[i] = _sin(ph) * e;
      ph += inc;
      if (ph >= 1) ph -= 1;
      e *= ka;
    }
    _emit(music, t, len, g, verb: verb, echo: echo, attack: 0.001, release: 0.02);
  }

  /// Sine bell with inharmonic partials, for distant pings.
  void ping(int t, int midi, double g, {double decay = 1.5, double verb = 0.7, double echo = 0.45}) {
    final f = _hz(midi), inc = f / sr;
    final len = secs(decay * 5);
    final b = _buf(len);
    final ka = _k(decay), k2 = _k(0.25), k3 = _k(0.06);
    final bright = math.min(len, secs(0.25 * 8));
    var ph = 0.0, e = 1.0, e2 = 1.0, e3 = 1.0;
    for (var i = 0; i < bright; i++) {
      b[i] = (_sin(ph) + 0.22 * _sin(2.76 * ph) * e2 + 0.08 * _sin(5.4 * ph) * e3) * e;
      ph += inc;
      e *= ka;
      e2 *= k2;
      e3 *= k3;
    }
    for (var i = bright; i < len; i++) {
      b[i] = _sin(ph) * e;
      ph += inc;
      e *= ka;
    }
    _emit(music, t, len, g, verb: verb, echo: echo, attack: 0.002, release: 0.05);
  }

  /// Sawtooth voice through a two-pole low-pass with an enveloped cutoff:
  /// plucks, leads, rolling bass and pulses.
  void synth(int t, int len, int midi, double g,
      {double cut0 = 3000, double cut1 = 600, double fdecay = 0.1, double decay = 0.3, double sustain = 0, int voices = 2, double detune = 8, double sub = 0, double verb = 0, double echo = 0}) {
    final b = _buf(len);
    final saw = _saw;
    final f = _hz(midi);
    final incs = Float64List(voices), phs = Float64List(voices);
    for (var v = 0; v < voices; v++) {
      final cents = voices == 1 ? 0.0 : detune * (2 * v / (voices - 1) - 1);
      incs[v] = f * math.pow(2, cents / 1200) / sr;
      phs[v] = rand();
    }
    final subInc = f / 2 / sr, kf = _k(fdecay), ka = _k(decay), gain = 1 / math.sqrt(voices.toDouble());
    var ps = 0.0, fe = 1.0, e = 1.0, l1 = 0.0, l2 = 0.0;
    for (var i = 0; i < len; i++) {
      var x = 0.0;
      for (var v = 0; v < voices; v++) {
        x += _wave(saw, phs[v]);
        final p = phs[v] + incs[v];
        phs[v] = p >= 1 ? p - 1 : p;
      }
      x *= gain;
      final w = _tau * (cut1 + (cut0 - cut1) * fe) / sr, a = w / (1 + w);
      fe *= kf;
      l1 += a * (x - l1);
      l2 += a * (l1 - l2);
      var y = l2;
      if (sub > 0) {
        y += sub * _sin(ps);
        ps += subInc;
        if (ps >= 1) ps -= 1;
      }
      b[i] = y * (sustain + (1 - sustain) * e);
      e *= ka;
    }
    _emit(music, t, len, g, verb: verb, echo: echo, attack: 0.002, release: 0.012);
  }

  /// Detuned sawtooth chord, low-passed, with smooth attack and release.
  void pad(int t, int len, List<int> notes, double g,
      {double attack = 0.4, double release = 0.6, double cutoff = 1800, int voices = 2, double detune = 8, double verb = 0.35}) {
    final b = _buf(len);
    b.fillRange(0, len, 0);
    final saw = _saw;
    for (final m in notes) {
      final f = _hz(m);
      for (var v = 0; v < voices; v++) {
        final cents = voices == 1 ? 0.0 : detune * (2 * v / (voices - 1) - 1);
        final inc = f * math.pow(2, cents / 1200) / sr;
        var ph = rand();
        for (var i = 0; i < len; i++) {
          b[i] += _wave(saw, ph);
          ph += inc;
          if (ph >= 1) ph -= 1;
        }
      }
    }
    final a = _a(cutoff), gain = 1 / math.sqrt((voices * notes.length).toDouble());
    final att = math.max(1, math.min(len ~/ 2, secs(attack)));
    final rel = math.max(1, math.min(len - att, secs(release)));
    var l1 = 0.0, l2 = 0.0;
    for (var i = 0; i < len; i++) {
      l1 += a * (b[i] - l1);
      l2 += a * (l1 - l2);
      var env = 1.0;
      if (i < att) {
        env = 0.5 - 0.5 * math.cos(math.pi * i / att);
      } else if (i >= len - rel) {
        env = 0.5 + 0.5 * math.cos(math.pi * (i - (len - rel)) / rel);
      }
      b[i] = l2 * gain * env;
    }
    _emit(music, t, len, g, verb: verb, attack: 0.001, release: 0.004);
  }

  // --- Mixdown -------------------------------------------------------------

  /// Feedback echo on the echo send, then reverb on the reverb send, then the
  /// sum of all buses. Effects are run over the end of the loop first so the
  /// output is their steady state and wraps without a seam.
  Float64List mixdown({
    double verb = 0.25,
    double room = 0.7,
    double damp = 0.4,
    int echoDelay = 0,
    double echoFb = 0.3,
    double echoLp = 3500,
    double echoGain = 0.3,
    double duck = 0,
  }) {
    if (echoDelay > 0 && echoGain > 0) {
      final ret = _echo(echoSend, echoDelay, echoFb, echoLp);
      for (var i = 0; i < n; i++) {
        final r = ret[i] * echoGain;
        music[i] += r;
        verbSend[i] += 0.5 * r;
      }
    }
    final wet = _reverb(verbSend, room, damp);
    final out = Float64List(n);
    var sum = 0.0, beat = 0.0;
    final beatInc = 1 / spb;
    for (var i = 0; i < n; i++) {
      var m = music[i];
      if (duck > 0) {
        m *= _pump(beat, duck);
        beat += beatInc;
        if (beat >= 1) beat -= 1;
      }
      final y = drums[i] + m + wet[i] * verb;
      out[i] = y;
      sum += y;
    }
    final mean = sum / n;
    for (var i = 0; i < n; i++) {
      out[i] -= mean;
    }
    return out;
  }

  /// Sidechain-style gain at position [p] (0..1) within the beat: dips right
  /// after the kick and recovers smoothly, back to 1 well before the next beat.
  static double _pump(double p, double depth) {
    const a = 0.012, r = 0.55;
    double d;
    if (p < a) {
      d = p / a;
    } else {
      final q = (p - a) / r;
      d = q >= 1 ? 0 : (1 - q) * (1 - q);
    }
    return 1 - depth * d;
  }

  int get _warmup => math.min(n, secs(5));

  Float64List _echo(Float64List x, int delay, double fb, double lpHz) {
    final y = Float64List(n);
    final a = _a(lpHz);
    var s = 0.0;
    for (var k = -_warmup; k < n; k++) {
      final i = k < 0 ? k + n : k;
      var j = i - delay;
      if (j < 0) j += n;
      s += a * (x[j] + fb * y[j] - s);
      y[i] = s;
    }
    return y;
  }

  /// Mono Freeverb (8 damped combs, 4 all-passes).
  Float64List _reverb(Float64List x, double room, double damp) {
    const combT = [1116, 1188, 1277, 1356, 1422, 1491, 1557, 1617];
    const apT = [556, 441, 341, 225];
    final scale = sr / 44100;
    // The end of the loop (warm-up) followed by the loop itself.
    final w = _warmup, total = w + n;
    final input = Float64List(total);
    for (var k = 0; k < total; k++) {
      final i = k - w;
      input[k] = x[i < 0 ? i + n : i] * 0.015;
    }
    // Parallel damped combs, each run over the whole signal...
    final acc = Float64List(total);
    final fb = room * 0.28 + 0.7, d1 = damp * 0.4, d2 = 1 - d1;
    for (final length in combT) {
      final buf = Float64List(math.max(1, (length * scale).round()));
      final size = buf.length;
      var p = 0, l = 0.0;
      for (var k = 0; k < total; k++) {
        final o = buf[p];
        l = o * d2 + l * d1;
        buf[p] = input[k] + l * fb;
        if (++p == size) p = 0;
        acc[k] += o;
      }
    }
    // ...then all-passes in series.
    for (final length in apT) {
      final buf = Float64List(math.max(1, (length * scale).round()));
      final size = buf.length;
      var p = 0;
      for (var k = 0; k < total; k++) {
        final v = acc[k];
        final o = buf[p];
        buf[p] = v + o * 0.5;
        if (++p == size) p = 0;
        acc[k] = o - v;
      }
    }
    final y = Float64List(n);
    for (var i = 0; i < n; i++) {
      y[i] = acc[w + i] * 12;
    }
    return y;
  }
}

/// Soft limiter and normalisation: the loudest peaks are rounded off by a
/// smooth knee, then the whole loop is scaled to a -1 dBFS peak. Both steps
/// are memoryless, so the loop point stays seamless.
Int16List _master(Float64List x) {
  var peak = 0.0;
  for (final v in x) {
    final a = v.abs();
    if (a > peak) peak = a;
  }
  final out = Int16List(x.length);
  if (peak == 0) return out;
  const knee = 0.6, target = 0.8912509381337456; // -1 dBFS
  final drive = 1.5 / peak;
  double shape(double v) {
    final a = v.abs();
    if (a <= knee) return v;
    final s = knee + (1 - knee) * _tanh((a - knee) / (1 - knee));
    return v < 0 ? -s : s;
  }

  final shaped = Float64List(x.length);
  var top = 0.0;
  for (var i = 0; i < x.length; i++) {
    final y = shape(x[i] * drive);
    shaped[i] = y;
    if (y.abs() > top) top = y.abs();
  }
  final scale = target * 32767 / top;
  for (var i = 0; i < x.length; i++) {
    out[i] = (shaped[i] * scale).round().clamp(-32768, 32767);
  }
  return out;
}

Uint8List _wav(Int16List pcm, int sampleRate) {
  final dataSize = pcm.length * 2;
  final bytes = Uint8List(44 + dataSize);
  final d = ByteData.sublistView(bytes);
  void tag(int offset, String s) {
    for (var i = 0; i < 4; i++) {
      bytes[offset + i] = s.codeUnitAt(i);
    }
  }

  tag(0, 'RIFF');
  d.setUint32(4, 36 + dataSize, Endian.little);
  tag(8, 'WAVE');
  tag(12, 'fmt ');
  d.setUint32(16, 16, Endian.little);
  d.setUint16(20, 1, Endian.little); // PCM
  d.setUint16(22, 1, Endian.little); // mono
  d.setUint32(24, sampleRate, Endian.little);
  d.setUint32(28, sampleRate * 2, Endian.little);
  d.setUint16(32, 2, Endian.little);
  d.setUint16(34, 16, Endian.little);
  tag(36, 'data');
  d.setUint32(40, dataSize, Endian.little);
  if (Endian.host == Endian.little) {
    bytes.setRange(44, bytes.length, pcm.buffer.asUint8List(pcm.offsetInBytes, dataSize));
  } else {
    for (var i = 0; i < pcm.length; i++) {
      d.setInt16(44 + 2 * i, pcm[i], Endian.little);
    }
  }
  return bytes;
}
