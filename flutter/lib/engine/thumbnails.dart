import 'dart:async';
import 'dart:io';

import 'package:ffmpeg_kit_flutter_new_min_gpl/ffmpeg_kit.dart';
import 'package:ffmpeg_kit_flutter_new_min_gpl/return_code.dart';
import 'package:path_provider/path_provider.dart';

import '../core/model/project.dart';

/// Filmstrip frames, extracted with FFmpeg.
///
/// One invocation produces the whole strip for a clip rather than one call per
/// frame. Process startup dominates the cost of a single thumbnail, so asking
/// for twelve at once is close to the price of asking for one, and it means
/// the strip fills in as a unit instead of arriving as a ragged sequence.
///
/// Results are cached on disk under a key derived from the clip and the strip
/// size, so scrolling the timeline back over ground it has already covered
/// costs nothing.
class Thumbnailer {
  const Thumbnailer._();

  static Directory? _cacheDir;

  /// How many extractions have been requested, for tests.
  ///
  /// The failure that made this worth counting was a widget re-running
  /// extraction on every rebuild, which is invisible to a test that only
  /// checks the widget renders.
  static int invocations = 0;

  static Future<Directory> _dir() async {
    final existing = _cacheDir;
    if (existing != null) return existing;
    final base = await getTemporaryDirectory();
    final dir = Directory('${base.path}/filmstrip');
    if (!await dir.exists()) await dir.create(recursive: true);
    _cacheDir = dir;
    return dir;
  }

  /// Frames spread evenly across the clip's trimmed range.
  ///
  /// Returns the paths in timeline order. A clip that yields nothing comes
  /// back empty rather than throwing: the strip draws plain blocks in that
  /// case, which is a better outcome than a timeline that refuses to render
  /// because one file is unreadable.
  static Future<List<String>> strip(
    TimelineClip clip, {
    int count = 10,
    int height = 120,
  }) async {
    if (count <= 0) return const [];
    invocations++;
    final dir = await _dir();
    final key = _keyFor(clip, count, height);
    final outDir = Directory('${dir.path}/$key');

    if (await outDir.exists()) {
      final cached = await _listFrames(outDir);
      // Any frames at all count as a hit. Demanding the exact number asked
      // for never succeeds, because the fps filter yields what it yields, so
      // the cache would be thrown away and rebuilt on every single call.
      if (cached.isNotEmpty) return cached;
      await outDir.delete(recursive: true);
    }
    await outDir.create(recursive: true);

    final input = _localPath(clip.uri);

    if (clip.isImage) {
      // A still has one frame; the strip repeats it rather than running
      // FFmpeg at all.
      final out = '${outDir.path}/f_001.jpg';
      final ok = await _run([
        '-i', input,
        '-vf', 'scale=-2:$height',
        '-frames:v', '1',
        '-y', out,
      ]);
      if (!ok) return const [];
      return List.filled(count, out);
    }

    final durationSeconds = clip.trimmedDurationUs / 1000000;
    if (durationSeconds <= 0) return const [];

    // fps is frames per second of source, so N frames across D seconds is N/D.
    // Expressed as a ratio rather than a decimal because FFmpeg parses the
    // ratio exactly and a rounded decimal drifts over a long clip.
    final ok = await _run([
      '-accurate_seek',
      '-ss', (clip.trimStartUs / 1000000).toStringAsFixed(4),
      '-t', durationSeconds.toStringAsFixed(4),
      '-i', input,
      '-vf', 'fps=$count/${durationSeconds.toStringAsFixed(4)},scale=-2:$height',
      '-frames:v', '$count',
      '-q:v', '5',
      '-y', '${outDir.path}/f_%03d.jpg',
    ]);
    if (!ok) return const [];

    return _listFrames(outDir);
  }

  /// A single frame at [timeUs] into the source, for the graded still.
  static Future<String?> frameAt(TimelineClip clip, int sourceTimeUs, {int height = 720}) async {
    final dir = await _dir();
    final snapped = (sourceTimeUs ~/ 200000) * 200000;
    final file = File(
      '${dir.path}/still_${_hash(clip.uri)}_${snapped}_$height.jpg',
    );
    if (await file.exists()) return file.path;

    final ok = await _run([
      '-accurate_seek',
      '-ss', (snapped / 1000000).toStringAsFixed(4),
      '-i', _localPath(clip.uri),
      '-vf', 'scale=-2:$height',
      '-frames:v', '1',
      '-q:v', '2',
      '-y', file.path,
    ]);
    return ok && await file.exists() ? file.path : null;
  }

  static Future<List<String>> _listFrames(Directory dir) async {
    final files = await dir
        .list()
        .where((e) => e is File && e.path.endsWith('.jpg'))
        .map((e) => e.path)
        .toList();
    files.sort();
    return files;
  }

  /// Serialises FFmpeg invocations.
  ///
  /// Each one loads the native libraries and allocates decode buffers, so a
  /// timeline that fires a dozen at once can exhaust native memory and take
  /// the process down — a crash with no Dart stack, which is the hardest kind
  /// to trace back. Queueing costs nothing here: the frames are wanted
  /// promptly, not simultaneously.
  static Future<void> _queue = Future.value();

  static Future<bool> _run(List<String> args) {
    final completer = Completer<bool>();
    _queue = _queue.then((_) async {
      try {
        final session = await FFmpegKit.executeWithArguments(args);
        final code = await session.getReturnCode();
        completer.complete(ReturnCode.isSuccess(code));
      } catch (_) {
        completer.complete(false);
      }
    });
    return completer.future;
  }

  /// Cache key covering everything that changes the pixels in the strip.
  ///
  /// Trim is included because trimming a clip changes which frames the strip
  /// should show; leaving it out would serve the old range from cache and make
  /// trimming look like it had done nothing.
  static String _keyFor(TimelineClip clip, int count, int height) =>
      '${_hash(clip.uri)}_${clip.trimStartUs}_${clip.trimEndUs}_${count}_$height';

  static String _hash(String value) {
    // A short stable digest, only ever used as a filename.
    var h = 0x811c9dc5;
    for (final unit in value.codeUnits) {
      h = ((h ^ unit) * 0x01000193) & 0xFFFFFFFF;
    }
    return h.toRadixString(16);
  }

  static String _localPath(String uri) {
    final parsed = Uri.tryParse(uri);
    if (parsed != null && parsed.scheme == 'file') {
      return parsed.toFilePath(windows: false);
    }
    return uri;
  }

  /// Drops every cached frame. Called when a project is closed, so a long
  /// session does not leave the cache directory growing without bound.
  static Future<void> clear() async {
    final dir = await _dir();
    if (await dir.exists()) await dir.delete(recursive: true);
    _cacheDir = null;
  }
}
