import 'dart:math' as math;

import 'adjustments.dart';
import 'overlay.dart';
import 'transition.dart';
import 'ids.dart';

/// Canvas shapes offered by the editor. Portrait leads, because phones do.
enum AspectRatio {
  portrait9x16('9:16', '9:16', 9, 16),
  portrait4x5('4:5', '4:5', 4, 5),
  portrait3x4('3:4', '3:4', 3, 4),
  portrait2x3('2:3', '2:3', 2, 3),
  square('1:1', '1:1', 1, 1),
  landscape16x9('16:9', '16:9', 16, 9),
  landscape4x3('4:3', '4:3', 4, 3),
  cinema21x9('21:9', '21:9', 21, 9);

  const AspectRatio(this.id, this.label, this.widthRatio, this.heightRatio);

  final String id;
  final String label;
  final int widthRatio;
  final int heightRatio;

  double get ratio => widthRatio / heightRatio;

  bool get isPortrait => heightRatio > widthRatio;

  /// Pixel size for a target short-edge resolution, rounded to even numbers.
  /// H.264 encoders reject odd dimensions.
  (int, int) sizeFor(int shortEdge) {
    int w;
    int h;
    if (widthRatio <= heightRatio) {
      w = shortEdge;
      h = (shortEdge * heightRatio / widthRatio).round();
    } else {
      h = shortEdge;
      w = (shortEdge * widthRatio / heightRatio).round();
    }
    return (_even(w), _even(h));
  }

  static int _even(int v) => v.isEven ? v : v + 1;

  static const AspectRatio defaultRatio = AspectRatio.portrait9x16;

  static AspectRatio fromId(String id) =>
      AspectRatio.values.firstWhere((a) => a.id == id, orElse: () => defaultRatio);

  /// The listed ratio nearest to [width] x [height].
  ///
  /// Used to size the canvas from the first clip imported. A fixed default is
  /// wrong half the time whichever way it points: landscape footage dropped
  /// into a 9:16 canvas comes up as a thin band between two black slabs, which
  /// reads as a broken import rather than as a canvas waiting to be changed.
  static AspectRatio closestTo(int width, int height) {
    if (width <= 0 || height <= 0) return defaultRatio;
    final target = width / height;
    return AspectRatio.values.reduce(
      (a, b) => (a.ratio - target).abs() <= (b.ratio - target).abs() ? a : b,
    );
  }
}

enum MediaKind { video, image, audio }

enum FitMode {
  fit('Fit'),
  fill('Fill'),
  stretch('Stretch');

  const FitMode(this.label);

  final String label;
}

class Transform {
  const Transform({
    this.scale = 1,
    this.offsetX = 0, // fraction of canvas width
    this.offsetY = 0, // fraction of canvas height
    this.rotationDegrees = 0,
    this.flipHorizontal = false,
    this.flipVertical = false,
    this.fit = FitMode.fit,
  });

  final double scale;
  final double offsetX;
  final double offsetY;
  final double rotationDegrees;
  final bool flipHorizontal;
  final bool flipVertical;
  final FitMode fit;

  Transform copyWith({
    double? scale,
    double? offsetX,
    double? offsetY,
    double? rotationDegrees,
    bool? flipHorizontal,
    bool? flipVertical,
    FitMode? fit,
  }) =>
      Transform(
        scale: scale ?? this.scale,
        offsetX: offsetX ?? this.offsetX,
        offsetY: offsetY ?? this.offsetY,
        rotationDegrees: rotationDegrees ?? this.rotationDegrees,
        flipHorizontal: flipHorizontal ?? this.flipHorizontal,
        flipVertical: flipVertical ?? this.flipVertical,
        fit: fit ?? this.fit,
      );

  Map<String, dynamic> toJson() => {
        'scale': scale,
        'offsetX': offsetX,
        'offsetY': offsetY,
        'rotationDegrees': rotationDegrees,
        'flipHorizontal': flipHorizontal,
        'flipVertical': flipVertical,
        'fit': fit.name,
      };

  static Transform fromJson(Map<String, dynamic> json) => Transform(
        scale: (json['scale'] as num?)?.toDouble() ?? 1,
        offsetX: (json['offsetX'] as num?)?.toDouble() ?? 0,
        offsetY: (json['offsetY'] as num?)?.toDouble() ?? 0,
        rotationDegrees: (json['rotationDegrees'] as num?)?.toDouble() ?? 0,
        flipHorizontal: json['flipHorizontal'] as bool? ?? false,
        flipVertical: json['flipVertical'] as bool? ?? false,
        fit: FitMode.values.firstWhere(
          (f) => f.name == json['fit'],
          orElse: () => FitMode.fit,
        ),
      );

  @override
  bool operator ==(Object other) =>
      other is Transform &&
      other.scale == scale &&
      other.offsetX == offsetX &&
      other.offsetY == offsetY &&
      other.rotationDegrees == rotationDegrees &&
      other.flipHorizontal == flipHorizontal &&
      other.flipVertical == flipVertical &&
      other.fit == fit;

  @override
  int get hashCode => Object.hash(scale, offsetX, offsetY, rotationDegrees,
      flipHorizontal, flipVertical, fit);
}

/// One item on the timeline.
///
/// Times are microseconds throughout, matching what the media layer reports
/// and what FFmpeg accepts, so no unit conversion sits between the model and
/// either consumer.
class Clip {
  Clip({
    String? id,
    required this.uri,
    required this.kind,
    required this.sourceDurationUs,
    this.trimStartUs = 0,
    int? trimEndUs,
    this.speed = 1,
    this.volume = 1,
    this.muted = false,
    this.adjustments = const Adjustments(),
    this.transform = const Transform(),
    this.fadeInUs = 0,
    this.fadeOutUs = 0,
    this.transition = Transition.none,
    this.sourceWidth = 0,
    this.sourceHeight = 0,
    this.sourceRotationDegrees = 0,
  })  : id = id ?? newId(),
        trimEndUs = trimEndUs ?? sourceDurationUs;

  final String id;
  final String uri;
  final MediaKind kind;

  /// Full length of the underlying file. Images get [defaultImageDurationUs].
  final int sourceDurationUs;
  final int trimStartUs;
  final int trimEndUs;
  final double speed;
  final double volume;
  final bool muted;
  final Adjustments adjustments;
  final Transform transform;
  final int fadeInUs;
  final int fadeOutUs;

  /// Transition into this clip from the one before it.
  final Transition transition;

  final int sourceWidth;
  final int sourceHeight;
  final int sourceRotationDegrees;

  static const int defaultImageDurationUs = 3000000;

  /// Nothing shorter than this is useful, and it keeps the trim maths safe.
  static const int minClipUs = 100000;

  /// Length of the trimmed region before speed is applied.
  int get trimmedDurationUs => math.max(0, trimEndUs - trimStartUs);

  /// Length this clip occupies on the timeline.
  int get timelineDurationUs =>
      speed <= 0 ? trimmedDurationUs : (trimmedDurationUs / speed).round();

  /// Display size after the source's own rotation metadata is applied.
  int get displayWidth =>
      sourceRotationDegrees % 180 == 0 ? sourceWidth : sourceHeight;

  int get displayHeight =>
      sourceRotationDegrees % 180 == 0 ? sourceHeight : sourceWidth;

  bool get isImage => kind == MediaKind.image;

  Clip withTrim(int startUs, int endUs) {
    final safeStart =
        startUs.clamp(0, math.max(0, sourceDurationUs - minClipUs)).toInt();
    final safeEnd =
        endUs.clamp(safeStart + minClipUs, sourceDurationUs).toInt();
    return copyWith(trimStartUs: safeStart, trimEndUs: safeEnd);
  }

  Clip copyWith({
    String? id,
    String? uri,
    MediaKind? kind,
    int? sourceDurationUs,
    int? trimStartUs,
    int? trimEndUs,
    double? speed,
    double? volume,
    bool? muted,
    Adjustments? adjustments,
    Transform? transform,
    int? fadeInUs,
    int? fadeOutUs,
    Transition? transition,
    int? sourceWidth,
    int? sourceHeight,
    int? sourceRotationDegrees,
  }) =>
      Clip(
        id: id ?? this.id,
        uri: uri ?? this.uri,
        kind: kind ?? this.kind,
        sourceDurationUs: sourceDurationUs ?? this.sourceDurationUs,
        trimStartUs: trimStartUs ?? this.trimStartUs,
        trimEndUs: trimEndUs ?? this.trimEndUs,
        speed: speed ?? this.speed,
        volume: volume ?? this.volume,
        muted: muted ?? this.muted,
        adjustments: adjustments ?? this.adjustments,
        transform: transform ?? this.transform,
        fadeInUs: fadeInUs ?? this.fadeInUs,
        fadeOutUs: fadeOutUs ?? this.fadeOutUs,
        transition: transition ?? this.transition,
        sourceWidth: sourceWidth ?? this.sourceWidth,
        sourceHeight: sourceHeight ?? this.sourceHeight,
        sourceRotationDegrees:
            sourceRotationDegrees ?? this.sourceRotationDegrees,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'uri': uri,
        'kind': kind.name,
        'sourceDurationUs': sourceDurationUs,
        'trimStartUs': trimStartUs,
        'trimEndUs': trimEndUs,
        'speed': speed,
        'volume': volume,
        'muted': muted,
        'adjustments': adjustments.toJson(),
        'transform': transform.toJson(),
        'fadeInUs': fadeInUs,
        'fadeOutUs': fadeOutUs,
        'transition': transition.toJson(),
        'sourceWidth': sourceWidth,
        'sourceHeight': sourceHeight,
        'sourceRotationDegrees': sourceRotationDegrees,
      };

  static Clip fromJson(Map<String, dynamic> json) {
    final duration = (json['sourceDurationUs'] as num?)?.toInt() ?? 0;
    return Clip(
      id: json['id'] as String?,
      uri: json['uri'] as String? ?? '',
      kind: MediaKind.values.firstWhere(
        (k) => k.name == json['kind'],
        orElse: () => MediaKind.video,
      ),
      sourceDurationUs: duration,
      trimStartUs: (json['trimStartUs'] as num?)?.toInt() ?? 0,
      trimEndUs: (json['trimEndUs'] as num?)?.toInt() ?? duration,
      speed: (json['speed'] as num?)?.toDouble() ?? 1,
      volume: (json['volume'] as num?)?.toDouble() ?? 1,
      muted: json['muted'] as bool? ?? false,
      adjustments: json['adjustments'] is Map
          ? Adjustments.fromJson(
              Map<String, dynamic>.from(json['adjustments'] as Map))
          : const Adjustments(),
      transform: json['transform'] is Map
          ? Transform.fromJson(Map<String, dynamic>.from(json['transform'] as Map))
          : const Transform(),
      fadeInUs: (json['fadeInUs'] as num?)?.toInt() ?? 0,
      fadeOutUs: (json['fadeOutUs'] as num?)?.toInt() ?? 0,
      transition: json['transition'] is Map
          ? Transition.fromJson(
              Map<String, dynamic>.from(json['transition'] as Map))
          : Transition.none,
      sourceWidth: (json['sourceWidth'] as num?)?.toInt() ?? 0,
      sourceHeight: (json['sourceHeight'] as num?)?.toInt() ?? 0,
      sourceRotationDegrees:
          (json['sourceRotationDegrees'] as num?)?.toInt() ?? 0,
    );
  }
}

class AudioClip {
  AudioClip({
    String? id,
    required this.uri,
    this.title = '',
    required this.sourceDurationUs,
    this.startOnTimelineUs = 0,
    this.trimStartUs = 0,
    int? trimEndUs,
    this.volume = 1,
    this.fadeInUs = 0,
    this.fadeOutUs = 0,
    this.loop = false,
  })  : id = id ?? newId(),
        trimEndUs = trimEndUs ?? sourceDurationUs;

  final String id;
  final String uri;
  final String title;
  final int sourceDurationUs;
  final int startOnTimelineUs;
  final int trimStartUs;
  final int trimEndUs;
  final double volume;
  final int fadeInUs;
  final int fadeOutUs;
  final bool loop;

  int get timelineDurationUs => math.max(0, trimEndUs - trimStartUs);

  int get endOnTimelineUs => startOnTimelineUs + timelineDurationUs;

  AudioClip copyWith({
    String? uri,
    String? title,
    int? sourceDurationUs,
    int? startOnTimelineUs,
    int? trimStartUs,
    int? trimEndUs,
    double? volume,
    int? fadeInUs,
    int? fadeOutUs,
    bool? loop,
  }) =>
      AudioClip(
        id: id,
        uri: uri ?? this.uri,
        title: title ?? this.title,
        sourceDurationUs: sourceDurationUs ?? this.sourceDurationUs,
        startOnTimelineUs: startOnTimelineUs ?? this.startOnTimelineUs,
        trimStartUs: trimStartUs ?? this.trimStartUs,
        trimEndUs: trimEndUs ?? this.trimEndUs,
        volume: volume ?? this.volume,
        fadeInUs: fadeInUs ?? this.fadeInUs,
        fadeOutUs: fadeOutUs ?? this.fadeOutUs,
        loop: loop ?? this.loop,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'uri': uri,
        'title': title,
        'sourceDurationUs': sourceDurationUs,
        'startOnTimelineUs': startOnTimelineUs,
        'trimStartUs': trimStartUs,
        'trimEndUs': trimEndUs,
        'volume': volume,
        'fadeInUs': fadeInUs,
        'fadeOutUs': fadeOutUs,
        'loop': loop,
      };

  static AudioClip fromJson(Map<String, dynamic> json) {
    final duration = (json['sourceDurationUs'] as num?)?.toInt() ?? 0;
    return AudioClip(
      id: json['id'] as String?,
      uri: json['uri'] as String? ?? '',
      title: json['title'] as String? ?? '',
      sourceDurationUs: duration,
      startOnTimelineUs: (json['startOnTimelineUs'] as num?)?.toInt() ?? 0,
      trimStartUs: (json['trimStartUs'] as num?)?.toInt() ?? 0,
      trimEndUs: (json['trimEndUs'] as num?)?.toInt() ?? duration,
      volume: (json['volume'] as num?)?.toDouble() ?? 1,
      fadeInUs: (json['fadeInUs'] as num?)?.toInt() ?? 0,
      fadeOutUs: (json['fadeOutUs'] as num?)?.toInt() ?? 0,
      loop: json['loop'] as bool? ?? false,
    );
  }
}

/// What to draw at a moment in time, including any transition in progress.
class Composition {
  const Composition({
    required this.primaryIndex,
    this.fromIndex = -1,
    this.progress = 0,
    this.transition = Transition.none,
  });

  final int primaryIndex;
  final int fromIndex;
  final double progress;
  final Transition transition;

  bool get isTransitioning => fromIndex >= 0 && transition.isActive;
}

class Project {
  Project({
    String? id,
    this.name = 'Untitled',
    this.aspect = AspectRatio.defaultRatio,
    this.clips = const [],
    this.audio = const [],
    this.overlays = const [],
    this.backgroundColor = 0xFF000000,
    int? createdAt,
  })  : id = id ?? newId(),
        createdAt = createdAt ?? DateTime.now().millisecondsSinceEpoch;

  final String id;
  final String name;
  final AspectRatio aspect;
  final List<Clip> clips;
  final List<AudioClip> audio;
  final List<Overlay> overlays;
  final int backgroundColor;
  final int createdAt;

  bool get isEmpty => clips.isEmpty && audio.isEmpty && overlays.isEmpty;

  int get durationUs {
    if (clips.isEmpty) return 0;
    return startOf(clips.length - 1) + clips.last.timelineDurationUs;
  }

  /// How far clip [index] overlaps the one before it.
  ///
  /// Clamped to half of the shorter neighbour so a long transition between two
  /// short clips cannot consume either of them entirely.
  int overlapBefore(int index) {
    if (index <= 0 || index >= clips.length) return 0;
    final transition = clips[index].transition;
    if (!transition.isActive) return 0;
    final shorter = math.min(
      clips[index - 1].timelineDurationUs,
      clips[index].timelineDurationUs,
    );
    return transition.durationUs.clamp(0, shorter ~/ 2);
  }

  /// Timeline start of [index], in microseconds.
  int startOf(int index) {
    var acc = 0;
    final limit = index.clamp(0, clips.length);
    for (var i = 0; i < limit; i++) {
      acc += clips[i].timelineDurationUs;
      acc -= overlapBefore(i + 1);
    }
    return acc;
  }

  /// Index of the clip covering [positionUs], or -1 past the end.
  ///
  /// Where two clips overlap in a transition the later one wins, so the
  /// playhead belongs to the incoming clip as soon as it starts.
  int clipIndexAt(int positionUs) {
    for (var index = clips.length - 1; index >= 0; index--) {
      final start = startOf(index);
      if (positionUs >= start &&
          positionUs < start + clips[index].timelineDurationUs) {
        return index;
      }
    }
    return -1;
  }

  Composition compositionAt(int positionUs) {
    final index = clipIndexAt(positionUs);
    if (index < 0) return const Composition(primaryIndex: -1);

    final overlap = overlapBefore(index);
    if (overlap <= 0 || index == 0) return Composition(primaryIndex: index);

    final into = positionUs - startOf(index);
    if (into >= overlap) return Composition(primaryIndex: index);

    return Composition(
      primaryIndex: index,
      fromIndex: index - 1,
      progress: (into / overlap).clamp(0.0, 1.0),
      transition: clips[index].transition,
    );
  }

  /// Source position inside clip [index] for timeline time [positionUs].
  int sourceTimeFor(int index, int positionUs) {
    if (index < 0 || index >= clips.length) return 0;
    final clip = clips[index];
    final into = math.max(0, positionUs - startOf(index));
    final scaled = (into * clip.speed).round();
    return (clip.trimStartUs + scaled).clamp(clip.trimStartUs, clip.trimEndUs);
  }

  List<Overlay> overlaysAt(int timeUs) =>
      overlays.where((o) => o.isActiveAt(timeUs)).toList();

  Project copyWith({
    String? name,
    AspectRatio? aspect,
    List<Clip>? clips,
    List<AudioClip>? audio,
    List<Overlay>? overlays,
    int? backgroundColor,
  }) =>
      Project(
        id: id,
        name: name ?? this.name,
        aspect: aspect ?? this.aspect,
        clips: clips ?? this.clips,
        audio: audio ?? this.audio,
        overlays: overlays ?? this.overlays,
        backgroundColor: backgroundColor ?? this.backgroundColor,
        createdAt: createdAt,
      );

  Project updateClip(String id, Clip Function(Clip) transform) => copyWith(
        clips: [
          for (final clip in clips) clip.id == id ? transform(clip) : clip,
        ],
      );

  Project updateAudio(String id, AudioClip Function(AudioClip) transform) =>
      copyWith(
        audio: [
          for (final track in audio) track.id == id ? transform(track) : track,
        ],
      );

  Project removeClip(String id) =>
      copyWith(clips: clips.where((c) => c.id != id).toList());

  Project addOverlay(Overlay overlay) =>
      copyWith(overlays: [...overlays, overlay]);

  Project removeOverlay(String id) =>
      copyWith(overlays: overlays.where((o) => o.id != id).toList());

  Project updateOverlay(String id, Overlay Function(Overlay) transform) =>
      copyWith(
        overlays: [
          for (final o in overlays) o.id == id ? transform(o) : o,
        ],
      );

  Project duplicateClip(String id) {
    final index = clips.indexWhere((c) => c.id == id);
    if (index < 0) return this;
    final next = List<Clip>.from(clips)
      ..insert(index + 1, clips[index].copyWith(id: newId()));
    return copyWith(clips: next);
  }

  Project moveClip(int from, int to) {
    if (from < 0 || from >= clips.length) return this;
    if (to < 0 || to >= clips.length || from == to) return this;
    final next = List<Clip>.from(clips);
    next.insert(to, next.removeAt(from));
    return copyWith(clips: next);
  }

  /// Splits the clip covering [positionUs] at that point.
  ///
  /// A split at the very start or end of a clip is a no-op rather than
  /// creating a zero-length one.
  Project splitAt(int positionUs) {
    final index = clipIndexAt(positionUs);
    if (index < 0) return this;
    final clip = clips[index];
    final offsetOnTimeline = positionUs - startOf(index);
    final cut = clip.trimStartUs + (offsetOnTimeline * clip.speed).round();

    if (cut - clip.trimStartUs < Clip.minClipUs) return this;
    if (clip.trimEndUs - cut < Clip.minClipUs) return this;

    final head = clip.copyWith(trimEndUs: cut, fadeOutUs: 0);
    final tail = clip.copyWith(
      id: newId(),
      trimStartUs: cut,
      fadeInUs: 0,
      // A split is a hard cut; inheriting the head's transition would insert
      // one in the middle of what was continuous footage.
      transition: Transition.none,
    );

    final next = List<Clip>.from(clips)
      ..[index] = head
      ..insert(index + 1, tail);
    return copyWith(clips: next);
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'aspect': aspect.id,
        'clips': clips.map((c) => c.toJson()).toList(),
        'audio': audio.map((a) => a.toJson()).toList(),
        'overlays': overlays.map((o) => o.toJson()).toList(),
        'backgroundColor': backgroundColor,
        'createdAt': createdAt,
      };

  static Project fromJson(Map<String, dynamic> json) => Project(
        id: json['id'] as String?,
        name: json['name'] as String? ?? 'Untitled',
        aspect: AspectRatio.fromId(json['aspect'] as String? ?? ''),
        clips: (json['clips'] as List? ?? [])
            .whereType<Map>()
            .map((c) => Clip.fromJson(Map<String, dynamic>.from(c)))
            .toList(),
        audio: (json['audio'] as List? ?? [])
            .whereType<Map>()
            .map((a) => AudioClip.fromJson(Map<String, dynamic>.from(a)))
            .toList(),
        overlays: (json['overlays'] as List? ?? [])
            .whereType<Map>()
            .map((o) => overlayFromJson(Map<String, dynamic>.from(o)))
            .whereType<Overlay>()
            .toList(),
        backgroundColor: (json['backgroundColor'] as num?)?.toInt() ?? 0xFF000000,
        createdAt: (json['createdAt'] as num?)?.toInt(),
      );
}
