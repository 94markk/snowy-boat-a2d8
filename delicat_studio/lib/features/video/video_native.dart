import 'dart:convert';

import 'package:flutter/services.dart';

class VideoInfo {
  const VideoInfo({required this.durationMs, required this.width, required this.height, required this.hasAudio});
  final int durationMs;
  final int width;
  final int height;
  final bool hasAudio;
}

/// Bridge to the Kotlin video engine (Media3 Transformer + MediaMetadataRetriever).
abstract final class VideoNative {
  static const _ch = MethodChannel('ht.delicat.studio/video');

  static Future<VideoInfo> probe(String path) async {
    final m = await _ch.invokeMapMethod<String, Object?>('probe', {'path': path});
    return VideoInfo(
      durationMs: (m!['durationMs'] as num).toInt(),
      width: (m['width'] as num).toInt(),
      height: (m['height'] as num).toInt(),
      hasAudio: m['hasAudio'] == true,
    );
  }

  /// JPEG frames evenly spread between [startMs] and [endMs] (whole file when 0).
  static Future<List<Uint8List>> thumbnails(String path, {int count = 8, int height = 96, int startMs = 0, int endMs = 0}) async {
    final list = await _ch.invokeListMethod<Object?>('thumbnails', {
      'path': path,
      'count': count,
      'height': height,
      'startMs': startMs,
      'endMs': endMs,
    });
    return [for (final b in list ?? const []) b as Uint8List];
  }

  /// Writes the exact frame at [timeMs] as a JPEG to [output].
  static Future<String> frame(String path, int timeMs, String output, {int maxSide = 2048}) async {
    final p = await _ch.invokeMethod<String>('frame', {'path': path, 'timeMs': timeMs, 'maxSide': maxSide, 'output': output});
    return p!;
  }

  /// Starts an export and completes with the output path when done.
  static Future<String> export(Map<String, Object?> spec) async {
    final p = await _ch.invokeMethod<String>('export', {'spec': jsonEncode(spec)});
    return p!;
  }

  /// 0..100, or -1 when no export is running.
  static Future<int> progress() async => (await _ch.invokeMethod<int>('progress')) ?? -1;

  static Future<void> cancel() => _ch.invokeMethod<void>('cancel');
}
