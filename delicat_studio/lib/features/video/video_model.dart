import 'dart:math' as math;

import 'package:material_ui/material_ui.dart';

import '../../core/color_matrix.dart';
import '../../core/styles.dart';
import '../editor/editor_model.dart';

/// Colour adjustments that can be baked into a video LUT (no vignette or grain).
const kVideoAdjustments = [Adj.brightness, Adj.contrast, Adj.saturation, Adj.exposure, Adj.warmth, Adj.tint, Adj.fade];

/// Length of the dip to black for a « Fondu » transition, on each side of the cut.
const kTransitionHalfMs = 300.0;

enum VRatio {
  original('Original', 0),
  story('9:16', 9 / 16),
  square('1:1', 1),
  portrait('4:5', 4 / 5),
  photo('3:4', 3 / 4),
  wide('16:9', 16 / 9);

  const VRatio(this.label, this.value);
  final String label;
  final double value;
}

enum VMotion {
  none('Aucun'),
  zoomIn('Zoom avant'),
  zoomOut('Zoom arrière');

  const VMotion(this.label);
  final String label;
}

List<double> colorMatrixFor(Map<Adj, double> adjust, String style, double amount) {
  double a(Adj x) => adjust[x] ?? 0;
  return ColorMatrix.chain([
    if (a(Adj.exposure) != 0) ColorMatrix.exposure(a(Adj.exposure)),
    if (a(Adj.brightness) != 0) ColorMatrix.brightness(a(Adj.brightness)),
    if (a(Adj.contrast) != 0) ColorMatrix.contrast(a(Adj.contrast)),
    if (a(Adj.saturation) != 0) ColorMatrix.saturation(a(Adj.saturation)),
    if (a(Adj.warmth) != 0) ColorMatrix.warmth(a(Adj.warmth)),
    if (a(Adj.tint) != 0) ColorMatrix.tint(a(Adj.tint)),
    if (a(Adj.fade) != 0) ColorMatrix.fade(a(Adj.fade)),
    if (style != 'original') styleById(style).matrix(amount),
  ]);
}

@immutable
class VClip {
  const VClip({
    required this.id,
    required this.path,
    required this.isImage,
    required this.sourceMs,
    required this.width,
    required this.height,
    required this.inMs,
    required this.outMs,
    this.hasAudio = true,
    this.speed = 1,
    this.volume = 1,
    this.mute = false,
    this.rotation = 0,
    this.flipH = false,
    this.style = 'original',
    this.styleAmount = 1,
    this.adjust = const {},
    this.motion = VMotion.none,
    this.fadeInMs = 0,
    this.fadeOutMs = 0,
    this.transitionIn = false,
  });

  final String id;
  final String path;
  final bool isImage;

  /// Source length (video) or the maximum still duration (image).
  final double sourceMs;

  /// Display size after the file's own rotation metadata.
  final int width;
  final int height;
  final double inMs;
  final double outMs;
  final bool hasAudio;
  final double speed;
  final double volume;
  final bool mute;

  /// Extra clockwise rotation chosen in the app: 0, 90, 180, 270.
  final int rotation;
  final bool flipH;
  final String style;
  final double styleAmount;
  final Map<Adj, double> adjust;
  final VMotion motion;
  final double fadeInMs;
  final double fadeOutMs;

  /// Dip to black from the previous clip.
  final bool transitionIn;

  double get durationMs => (outMs - inMs) / speed;
  bool get rotated => rotation % 180 != 0;
  double get aspect => rotated ? height / width : width / height;
  bool get hasColorWork => style != 'original' || adjust.values.any((v) => v != 0);
  List<double> matrix() => colorMatrixFor(adjust, style, styleAmount);

  /// Source position for a clip-local timeline position.
  double sourceAt(double localMs) => inMs + localMs.clamp(0, durationMs) * speed;

  VClip copyWith({
    String? id,
    double? inMs,
    double? outMs,
    double? speed,
    double? volume,
    bool? mute,
    int? rotation,
    bool? flipH,
    String? style,
    double? styleAmount,
    Map<Adj, double>? adjust,
    VMotion? motion,
    double? fadeInMs,
    double? fadeOutMs,
    bool? transitionIn,
  }) =>
      VClip(
        id: id ?? this.id,
        path: path,
        isImage: isImage,
        sourceMs: sourceMs,
        width: width,
        height: height,
        hasAudio: hasAudio,
        inMs: inMs ?? this.inMs,
        outMs: outMs ?? this.outMs,
        speed: speed ?? this.speed,
        volume: volume ?? this.volume,
        mute: mute ?? this.mute,
        rotation: rotation ?? this.rotation,
        flipH: flipH ?? this.flipH,
        style: style ?? this.style,
        styleAmount: styleAmount ?? this.styleAmount,
        adjust: adjust ?? this.adjust,
        motion: motion ?? this.motion,
        fadeInMs: fadeInMs ?? this.fadeInMs,
        fadeOutMs: fadeOutMs ?? this.fadeOutMs,
        transitionIn: transitionIn ?? this.transitionIn,
      );
}

/// Text or sticker shown between [startMs] and [endMs].
@immutable
class VText {
  const VText({required this.layer, required this.startMs, required this.endMs});
  final EditorLayer layer;
  final double startMs;
  final double endMs;
  String get id => layer.id;

  bool visibleAt(double t) => t >= startMs && t < endMs;

  VText copyWith({EditorLayer? layer, double? startMs, double? endMs}) =>
      VText(layer: layer ?? this.layer, startMs: startMs ?? this.startMs, endMs: endMs ?? this.endMs);
}

@immutable
class VMusic {
  const VMusic({required this.path, required this.name, required this.sourceMs, this.inMs = 0, this.volume = 0.8, this.loop = true});
  final String path;
  final String name;
  final double sourceMs;

  /// Where in the song the video starts.
  final double inMs;
  final double volume;
  final bool loop;

  VMusic copyWith({double? inMs, double? volume, bool? loop}) =>
      VMusic(path: path, name: name, sourceMs: sourceMs, inMs: inMs ?? this.inMs, volume: volume ?? this.volume, loop: loop ?? this.loop);
}

/// Watermark logo pinned to a corner for the whole video.
@immutable
class VLogo {
  const VLogo({required this.path, this.corner = Alignment.bottomRight, this.size = 0.2, this.opacity = 0.9});
  final String path;
  final Alignment corner;

  /// Width as a fraction of the frame width.
  final double size;
  final double opacity;

  VLogo copyWith({Alignment? corner, double? size, double? opacity}) =>
      VLogo(path: path, corner: corner ?? this.corner, size: size ?? this.size, opacity: opacity ?? this.opacity);
}

@immutable
class VideoDoc {
  const VideoDoc({
    this.clips = const [],
    this.texts = const [],
    this.music,
    this.logo,
    this.ratio = VRatio.original,
    this.fill = false,
    this.muteAll = false,
  });

  final List<VClip> clips;
  final List<VText> texts;
  final VMusic? music;
  final VLogo? logo;
  final VRatio ratio;

  /// Crop to fill the frame instead of letterboxing.
  final bool fill;

  /// Silences the original sound of every clip (music only).
  final bool muteAll;

  double get durationMs => clips.fold(0.0, (s, c) => s + c.durationMs);

  double startOf(int index) {
    var s = 0.0;
    for (var i = 0; i < index && i < clips.length; i++) {
      s += clips[i].durationMs;
    }
    return s;
  }

  /// Index of the clip at timeline position [t] (last clip at the very end).
  int indexAt(double t) {
    var s = 0.0;
    for (var i = 0; i < clips.length; i++) {
      final e = s + clips[i].durationMs;
      if (t < e) return i;
      s = e;
    }
    return clips.isEmpty ? -1 : clips.length - 1;
  }

  /// Output aspect ratio (width / height).
  double get aspect {
    if (ratio != VRatio.original) return ratio.value;
    return clips.isEmpty ? 9 / 16 : clips.first.aspect;
  }

  /// Brightness multiplier at [t] from clip fades and « Fondu » transitions (0..1).
  double fadeAt(double t) {
    final i = indexAt(t);
    if (i < 0) return 1;
    final c = clips[i];
    final start = startOf(i);
    final local = t - start;
    final left = c.durationMs - local;
    final fin = math.max(c.fadeInMs, c.transitionIn && i > 0 ? kTransitionHalfMs : 0);
    final next = i + 1 < clips.length ? clips[i + 1] : null;
    final fout = math.max(c.fadeOutMs, next != null && next.transitionIn ? kTransitionHalfMs : 0);
    var f = 1.0;
    if (fin > 0 && local < fin) f = math.min(f, local / fin);
    if (fout > 0 && left < fout) f = math.min(f, left / fout);
    return f.clamp(0.0, 1.0);
  }

  /// Zoom factor at [t] for clips with a motion effect.
  double zoomAt(double t) {
    final i = indexAt(t);
    if (i < 0) return 1;
    final c = clips[i];
    final p = ((t - startOf(i)) / math.max(1, c.durationMs)).clamp(0.0, 1.0);
    return switch (c.motion) {
      VMotion.none => 1,
      VMotion.zoomIn => 1 + 0.18 * p,
      VMotion.zoomOut => 1.18 - 0.18 * p,
    };
  }

  VideoDoc copyWith({
    List<VClip>? clips,
    List<VText>? texts,
    VMusic? music,
    bool clearMusic = false,
    VLogo? logo,
    bool clearLogo = false,
    VRatio? ratio,
    bool? fill,
    bool? muteAll,
  }) =>
      VideoDoc(
        clips: clips ?? this.clips,
        texts: texts ?? this.texts,
        music: clearMusic ? null : (music ?? this.music),
        logo: clearLogo ? null : (logo ?? this.logo),
        ratio: ratio ?? this.ratio,
        fill: fill ?? this.fill,
        muteAll: muteAll ?? this.muteAll,
      );

  VideoDoc replaceClip(VClip c) => copyWith(clips: [for (final x in clips) x.id == c.id ? c : x]);
  VideoDoc replaceText(VText t) => copyWith(texts: [for (final x in texts) x.id == t.id ? t : x]);

  /// Keeps texts inside the timeline after clips change length.
  VideoDoc clampTexts() {
    final d = durationMs;
    final next = <VText>[];
    for (final t in texts) {
      if (t.startMs >= d - 50) continue;
      next.add(t.copyWith(endMs: math.min(t.endMs, d)));
    }
    return copyWith(texts: next);
  }

  /// Splits clip [index] at timeline time [t]. Returns null if too close to an edge.
  VideoDoc? splitAt(int index, double t, String newId) {
    final c = clips[index];
    final local = t - startOf(index);
    if (local < 100 || c.durationMs - local < 100) return null;
    final cut = c.inMs + local * c.speed;
    final a = c.copyWith(outMs: cut, fadeOutMs: 0);
    final b = c.copyWith(id: newId, inMs: cut, fadeInMs: 0, transitionIn: false);
    return copyWith(clips: [...clips.sublist(0, index), a, b, ...clips.sublist(index + 1)]);
  }

  /// Segments describing fades and motion per clip, in timeline milliseconds (for export).
  List<Map<String, Object>> timelineSpec() {
    final out = <Map<String, Object>>[];
    for (var i = 0; i < clips.length; i++) {
      final c = clips[i];
      final s = startOf(i);
      final next = i + 1 < clips.length ? clips[i + 1] : null;
      out.add({
        'startMs': s.round(),
        'endMs': (s + c.durationMs).round(),
        'fadeInMs': math.max(c.fadeInMs, c.transitionIn && i > 0 ? kTransitionHalfMs : 0).round(),
        'fadeOutMs': math.max(c.fadeOutMs, next != null && next.transitionIn ? kTransitionHalfMs : 0).round(),
        'zoom': switch (c.motion) {
          VMotion.none => 'none',
          VMotion.zoomIn => 'in',
          VMotion.zoomOut => 'out',
        },
      });
    }
    return out;
  }

  /// Time ranges where the overlay picture (texts, stickers, logo) is constant.
  List<(double, double, List<VText>)> overlaySegments() {
    final d = durationMs;
    final cuts = <double>{0, d};
    for (final t in texts) {
      cuts.add(t.startMs.clamp(0, d));
      cuts.add(t.endMs.clamp(0, d));
    }
    final sorted = cuts.toList()..sort();
    final out = <(double, double, List<VText>)>[];
    for (var i = 0; i + 1 < sorted.length; i++) {
      final a = sorted[i];
      final b = sorted[i + 1];
      if (b - a < 1) continue;
      final mid = (a + b) / 2;
      final visible = texts.where((t) => t.visibleAt(mid)).toList();
      if (visible.isEmpty && logo == null) continue;
      out.add((a, b, visible));
    }
    return out;
  }
}

/// Output size for an aspect ratio and a short-side resolution (even numbers for the encoder).
Size exportSize(double aspect, int shortSide) {
  int even(double v) => (v / 2).round() * 2;
  return aspect >= 1 ? Size(even(shortSide * aspect).toDouble(), shortSide.toDouble()) : Size(shortSide.toDouble(), even(shortSide / aspect).toDouble());
}
