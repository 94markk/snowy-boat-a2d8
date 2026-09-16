import 'dart:convert';
import 'dart:io';

import 'package:ffmpeg_kit_flutter_new_min_gpl/ffprobe_kit.dart';

import '../core/model/project.dart';

/// What the timeline needs to know about a file before it can lay it out.
class MediaInfo {
  const MediaInfo({
    required this.kind,
    required this.durationUs,
    required this.width,
    required this.height,
    required this.rotationDegrees,
    required this.hasAudio,
  });

  final MediaKind kind;
  final int durationUs;
  final int width;
  final int height;
  final int rotationDegrees;
  final bool hasAudio;
}

/// Reads media with ffprobe.
///
/// Detection does not trust the file extension. A picker can hand back a path
/// with no extension at all, or the wrong one, so the decision is made from
/// what the streams actually say. A file that has a video stream with a real
/// duration is video; one whose only video stream is a single frame is a
/// still, whatever it is called.
class MediaProbe {
  const MediaProbe._();

  static Future<MediaInfo?> probe(String path) async {
    try {
      final session = await FFprobeKit.getMediaInformation(path);
      final info = session.getMediaInformation();
      if (info == null) return null;

      final raw = info.getAllProperties();
      if (raw == null) return null;
      final json = Map<String, dynamic>.from(raw);

      final streams = (json['streams'] as List? ?? [])
          .whereType<Map>()
          .map((s) => Map<String, dynamic>.from(s))
          .toList();

      Map<String, dynamic>? video;
      var hasAudio = false;
      for (final stream in streams) {
        final type = stream['codec_type'];
        if (type == 'video') {
          video ??= stream;
        } else if (type == 'audio') {
          hasAudio = true;
        }
      }
      if (video == null) return null;

      final durationSeconds =
          double.tryParse('${json['format']?['duration'] ?? ''}') ?? 0;
      final durationUs = (durationSeconds * 1000000).round();

      final width = (video['width'] as num?)?.toInt() ?? 0;
      final height = (video['height'] as num?)?.toInt() ?? 0;

      // A still reports either no duration or a single frame. Image codecs
      // are checked too, because some encoders write a nominal duration into
      // a JPEG and it is not one worth believing.
      final codec = '${video['codec_name'] ?? ''}';
      final isStillCodec = const {
        'mjpeg', 'png', 'bmp', 'gif', 'webp', 'heif', 'hevc_still', 'avif',
      }.contains(codec);
      final frames = int.tryParse('${video['nb_frames'] ?? ''}') ?? 0;
      final isStill = isStillCodec || frames == 1 || durationUs <= 0;

      return MediaInfo(
        kind: isStill ? MediaKind.image : MediaKind.video,
        durationUs: isStill ? TimelineClip.defaultImageDurationUs : durationUs,
        width: width,
        height: height,
        rotationDegrees: _rotationOf(video),
        hasAudio: hasAudio && !isStill,
      );
    } catch (_) {
      return null;
    }
  }

  /// Builds a timeline clip, or null if the file cannot be read at all.
  static Future<TimelineClip?> clipFor(String path) async {
    if (!await File(path).exists()) return null;
    final info = await probe(path);
    if (info == null) return null;

    // A stream can be playable while its duration header is missing or zero.
    // Falling back to a still-image length keeps the clip on the timeline,
    // trimmable, instead of discarding a usable file.
    final duration = info.durationUs > 0
        ? info.durationUs
        : TimelineClip.defaultImageDurationUs;

    return TimelineClip(
      uri: Uri.file(path).toString(),
      kind: info.kind,
      sourceDurationUs:
          duration < TimelineClip.minClipUs ? TimelineClip.minClipUs : duration,
      sourceWidth: info.width,
      sourceHeight: info.height,
      sourceRotationDegrees: info.rotationDegrees,
    );
  }

  /// Rotation from either the stream tag or a display matrix side-data entry.
  ///
  /// Phones record in the sensor's orientation and describe the correction in
  /// metadata rather than rotating the pixels, so a portrait video is stored
  /// landscape. Missing this is what makes an editor look like it is stuck in
  /// the wrong orientation.
  static int _rotationOf(Map<String, dynamic> video) {
    final tagged = int.tryParse('${video['tags']?['rotate'] ?? ''}');
    if (tagged != null) return ((tagged % 360) + 360) % 360;

    final sideData = (video['side_data_list'] as List? ?? [])
        .whereType<Map>()
        .map((e) => Map<String, dynamic>.from(e));
    for (final entry in sideData) {
      final rotation = entry['rotation'];
      if (rotation is num) {
        // The display matrix expresses the rotation to undo, so its sign is
        // the opposite of the one the timeline wants.
        final degrees = (-rotation).round();
        return ((degrees % 360) + 360) % 360;
      }
    }
    return 0;
  }

  /// Raw ffprobe JSON, for diagnostics.
  static Future<String?> describe(String path) async {
    final info = await probe(path);
    if (info == null) return null;
    return jsonEncode({
      'kind': info.kind.name,
      'durationUs': info.durationUs,
      'size': '${info.width}x${info.height}',
      'rotation': info.rotationDegrees,
      'hasAudio': info.hasAudio,
    });
  }
}
