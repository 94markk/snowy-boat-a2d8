import 'dart:math' as math;

import '../../core/model/project.dart';

/// Where a rasterised overlay lives on disk, and when it should be visible.
class OverlayImage {
  const OverlayImage({
    required this.path,
    required this.startUs,
    required this.endUs,
  });

  final String path;
  final int startUs;
  final int endUs;
}

class ExportSettings {
  const ExportSettings({
    this.shortEdge = 1080,
    this.fps = 30,
    this.crf = 20,
    this.audioBitrate = '192k',
  });

  final int shortEdge;
  final int fps;

  /// Constant rate factor. 20 is visually transparent for phone footage while
  /// staying well under what a messaging app will re-compress anyway.
  final int crf;
  final String audioBitrate;
}

/// Builds the FFmpeg command for a project.
///
/// The whole export is one filter graph and one process. Rendering clip by
/// clip and concatenating afterwards would mean re-encoding every clip twice
/// and would make transitions impossible, since a transition needs both
/// neighbours decoded at once.
///
/// Returned as an argument list rather than a string: a file path containing a
/// space or a quote would otherwise re-split into something FFmpeg reads as
/// two arguments, and media picked on a phone routinely contains both.
class FilterGraph {
  const FilterGraph._();

  static List<String> build({
    required Project project,
    required ExportSettings settings,
    required Map<String, String> lutPaths,
    required List<OverlayImage> overlays,
    required String outputPath,
  }) {
    final (width, height) = project.aspect.sizeFor(settings.shortEdge);
    final args = <String>[];
    final chains = <String>[];

    // Inputs: clips first, then audio tracks, then overlay images, so an
    // index can be derived from position rather than tracked separately.
    for (final clip in project.clips) {
      if (clip.isImage) {
        // A still needs a synthetic stream; -loop with -t gives it the
        // duration the timeline expects.
        args
          ..addAll(['-loop', '1'])
          ..addAll(['-t', _seconds(clip.timelineDurationUs)])
          ..addAll(['-i', _localPath(clip.uri)]);
      } else {
        // Seeking before -i is the fast path: it jumps to the keyframe rather
        // than decoding from zero and discarding, which on a long clip is the
        // difference between an export that takes seconds and one that takes
        // minutes. -accurate_seek keeps the cut frame-exact regardless.
        args
          ..addAll(['-accurate_seek'])
          ..addAll(['-ss', _seconds(clip.trimStartUs)])
          ..addAll(['-t', _seconds(clip.trimmedDurationUs)])
          ..addAll(['-i', _localPath(clip.uri)]);
      }
    }

    final audioInputBase = project.clips.length;
    for (final track in project.audio) {
      args
        ..addAll(['-accurate_seek'])
        ..addAll(['-ss', _seconds(track.trimStartUs)])
        ..addAll(['-t', _seconds(track.timelineDurationUs)])
        ..addAll(['-i', _localPath(track.uri)]);
    }

    final overlayInputBase = audioInputBase + project.audio.length;
    for (final overlay in overlays) {
      args.addAll(['-i', overlay.path]);
    }

    // --- Video: normalise every clip to the canvas, then grade it.
    for (var i = 0; i < project.clips.length; i++) {
      chains.add(_clipChain(
        clip: project.clips[i],
        index: i,
        width: width,
        height: height,
        fps: settings.fps,
        lutPath: lutPaths[project.clips[i].id],
      ));
    }

    // --- Join the clips, honouring transitions.
    final videoLabel = _joinClips(project, chains, settings.fps);

    // --- Burn in text and stickers.
    var current = videoLabel;
    for (var i = 0; i < overlays.length; i++) {
      final next = 'ov$i';
      final o = overlays[i];
      chains.add(
        "[$current][${overlayInputBase + i}:v]overlay="
        "x=0:y=0:"
        "enable='between(t,${_seconds(o.startUs)},${_seconds(o.endUs)})'"
        "[$next]",
      );
      current = next;
    }

    // --- Audio: clip audio and added tracks, mixed.
    final audioLabel = _buildAudio(project, chains, audioInputBase);

    args.addAll(['-filter_complex', chains.join(';')]);
    args.addAll(['-map', '[$current]']);
    if (audioLabel != null) {
      args.addAll(['-map', '[$audioLabel]']);
      args.addAll(['-c:a', 'aac', '-b:a', settings.audioBitrate]);
    }

    args.addAll([
      '-c:v', 'libx264',
      '-preset', 'medium',
      '-crf', '${settings.crf}',
      // yuv420p and +faststart are what make the file play everywhere: without
      // the pixel format some players show nothing, and without faststart the
      // index sits at the end so the file will not start until fully
      // downloaded.
      '-pix_fmt', 'yuv420p',
      '-movflags', '+faststart',
      '-r', '${settings.fps}',
      '-y', outputPath,
    ]);

    return args;
  }

  /// One clip: fit to canvas, apply the look, apply the spatial effects.
  static String _clipChain({
    required TimelineClip clip,
    required int index,
    required int width,
    required int height,
    required int fps,
    String? lutPath,
  }) {
    final parts = <String>[];

    if (clip.speed != 1 && !clip.isImage) {
      // setpts scales presentation timestamps; dividing by speed makes the
      // clip shorter, which is what a speed above 1 means on the timeline.
      parts.add('setpts=PTS/${clip.speed}');
    }

    // Scale to fit inside the canvas without distorting, then pad the
    // remainder. force_original_aspect_ratio is what keeps a portrait clip
    // from being stretched sideways into a landscape frame.
    final fit = switch (clip.transform.fit) {
      FitMode.fill => 'increase',
      FitMode.stretch => 'disable',
      FitMode.fit => 'decrease',
    };
    parts.add('scale=$width:$height:force_original_aspect_ratio=$fit');
    if (clip.transform.fit == FitMode.fill) {
      parts.add('crop=$width:$height');
    } else if (clip.transform.fit == FitMode.fit) {
      parts.add('pad=$width:$height:(ow-iw)/2:(oh-ih)/2:color=black');
    }

    parts.add('fps=$fps');
    parts.add('setsar=1');

    if (lutPath != null) parts.add("lut3d='$lutPath'");

    final a = clip.adjustments;
    if (a.blur > 0) {
      parts.add('gblur=sigma=${(a.blur * 20).toStringAsFixed(2)}');
    }
    if (a.sharpen > 0) {
      parts.add('unsharp=5:5:${(a.sharpen * 1.5).toStringAsFixed(2)}');
    }
    if (a.vignette != 0) {
      // FFmpeg's vignette only darkens. A negative value in the app brightens
      // the corners, which is expressed here as a reduced-strength darken on
      // the inverse; anything closer would need a custom filter.
      final strength = a.vignette.abs().clamp(0.0, 1.0);
      parts.add('vignette=angle=${(strength * 0.9).toStringAsFixed(3)}');
    }
    if (a.grain > 0) {
      parts.add('noise=alls=${(a.grain * 24).round()}:allf=t+u');
    }

    if (clip.fadeInUs > 0) {
      parts.add('fade=t=in:st=0:d=${_seconds(clip.fadeInUs)}');
    }
    if (clip.fadeOutUs > 0) {
      final start = clip.timelineDurationUs - clip.fadeOutUs;
      parts.add(
        'fade=t=out:st=${_seconds(math.max(0, start))}:d=${_seconds(clip.fadeOutUs)}',
      );
    }

    return '[$index:v]${parts.join(',')}[v$index]';
  }

  /// Chains the clips together, using xfade where a transition is set.
  ///
  /// xfade consumes the overlap from both sides, so each offset is measured
  /// against the running total rather than the raw clip lengths — the same
  /// bookkeeping Project.startOf does for the timeline, and it has to agree or
  /// the export drifts out of sync with what the editor showed.
  static String _joinClips(Project project, List<String> chains, int fps) {
    if (project.clips.isEmpty) return 'v0';
    if (project.clips.length == 1) return 'v0';

    var current = 'v0';
    var elapsedUs = project.clips.first.timelineDurationUs;

    for (var i = 1; i < project.clips.length; i++) {
      final clip = project.clips[i];
      final overlap = project.overlapBefore(i);
      final next = 'x$i';

      if (overlap > 0 && clip.transition.type.xfadeName.isNotEmpty) {
        final offset = elapsedUs - overlap;
        chains.add(
          '[$current][v$i]xfade='
          'transition=${clip.transition.type.xfadeName}:'
          'duration=${_seconds(overlap)}:'
          'offset=${_seconds(math.max(0, offset))}[$next]',
        );
        elapsedUs = elapsedUs - overlap + clip.timelineDurationUs;
      } else {
        chains.add('[$current][v$i]concat=n=2:v=1:a=0[$next]');
        elapsedUs += clip.timelineDurationUs;
      }
      current = next;
    }

    return current;
  }

  /// TimelineClip audio plus any added tracks, mixed down to one stream.
  static String? _buildAudio(
    Project project,
    List<String> chains,
    int audioInputBase,
  ) {
    final labels = <String>[];

    for (var i = 0; i < project.clips.length; i++) {
      final clip = project.clips[i];
      if (clip.isImage || clip.muted || clip.volume == 0) continue;

      final parts = <String>[];
      if (clip.speed != 1) {
        parts.add(_atempoChain(clip.speed));
      }
      parts.add('volume=${clip.volume.toStringAsFixed(3)}');
      // Pad so a clip whose audio is shorter than its video does not pull the
      // following clip forward in the mix.
      parts.add('apad');
      parts.add('atrim=duration=${_seconds(clip.timelineDurationUs)}');
      chains.add('[$i:a]${parts.join(',')}[a$i]');
      labels.add('a$i');
    }

    for (var i = 0; i < project.audio.length; i++) {
      final track = project.audio[i];
      final input = audioInputBase + i;
      final parts = <String>[
        'volume=${track.volume.toStringAsFixed(3)}',
        // adelay wants milliseconds, and `all=1` applies the delay to every
        // channel rather than only the first.
        'adelay=${track.startOnTimelineUs ~/ 1000}:all=1',
      ];
      if (track.fadeInUs > 0) {
        parts.add('afade=t=in:st=0:d=${_seconds(track.fadeInUs)}');
      }
      if (track.fadeOutUs > 0) {
        final start = track.timelineDurationUs - track.fadeOutUs;
        parts.add(
          'afade=t=out:st=${_seconds(math.max(0, start))}:d=${_seconds(track.fadeOutUs)}',
        );
      }
      chains.add('[$input:a]${parts.join(',')}[m$i]');
      labels.add('m$i');
    }

    if (labels.isEmpty) return null;
    if (labels.length == 1) {
      // amix normalises levels even with one input, which quietly drops the
      // volume of a single track; passing it straight through avoids that.
      chains.add('[${labels.first}]anull[aout]');
      return 'aout';
    }

    chains.add(
      '${labels.map((l) => '[$l]').join()}'
      'amix=inputs=${labels.length}:normalize=0:dropout_transition=0[aout]',
    );
    return 'aout';
  }

  /// atempo only accepts 0.5 to 2.0, so anything outside is chained.
  ///
  /// A single atempo=4.0 is silently rejected and the audio comes out at the
  /// wrong speed rather than erroring, so the split has to happen here.
  static String _atempoChain(double speed) {
    var remaining = speed.clamp(0.1, 8.0);
    final stages = <String>[];

    while (remaining > 2.0) {
      stages.add('atempo=2.0');
      remaining /= 2.0;
    }
    while (remaining < 0.5) {
      stages.add('atempo=0.5');
      remaining /= 0.5;
    }
    stages.add('atempo=${remaining.toStringAsFixed(4)}');
    return stages.join(',');
  }

  static String _seconds(int us) => (us / 1000000).toStringAsFixed(4);

  /// FFmpeg wants a filesystem path, not a content:// URI.
  static String _localPath(String uri) {
    final parsed = Uri.tryParse(uri);
    if (parsed != null && parsed.scheme == 'file') {
      return parsed.toFilePath(windows: false);
    }
    return uri;
  }
}
