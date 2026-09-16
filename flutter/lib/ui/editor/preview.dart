import 'dart:io';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';

import '../../core/model/adjustments.dart';
import '../../core/model/project.dart';
import '../../engine/thumbnails.dart';
import '../theme/theme.dart';
import 'graded_image.dart';

/// The canvas.
///
/// Two modes, for a reason worth stating plainly. A Flutter fragment shader
/// cannot sample a video texture, so grading cannot be applied to moving video
/// in pure Dart. Paused, the frame under the playhead is decoded to an image
/// and run through the real grading shader, which is exact. Playing, the video
/// is shown through a colour matrix that approximates the grade.
///
/// The split follows how grading is actually done: you set a look on a still
/// frame and play back to check the cut. The approximate pass exists so
/// playback does not snap to obviously wrong colour, not because it is good
/// enough to judge by.
class EditorPreview extends StatefulWidget {
  const EditorPreview({
    super.key,
    required this.clip,
    required this.sourceTimeUs,
    required this.controller,
    required this.isPlaying,
    required this.aspect,
    required this.backgroundColor,
  });

  final TimelineClip? clip;
  final int sourceTimeUs;
  final VideoPlayerController? controller;
  final bool isPlaying;
  final CanvasRatio aspect;
  final int backgroundColor;

  @override
  State<EditorPreview> createState() => _EditorPreviewState();
}

class _EditorPreviewState extends State<EditorPreview> {
  ui.Image? _still;
  String? _stillKey;
  bool _loading = false;

  @override
  void initState() {
    super.initState();
    _loadStill();
  }

  @override
  void didUpdateWidget(EditorPreview old) {
    super.didUpdateWidget(old);
    if (widget.isPlaying) return;
    if (old.clip?.id != widget.clip?.id ||
        old.sourceTimeUs != widget.sourceTimeUs ||
        old.isPlaying != widget.isPlaying) {
      _loadStill();
    }
  }

  Future<void> _loadStill() async {
    final clip = widget.clip;
    if (clip == null || _loading) return;

    // Snapping matches the thumbnailer's own cache granularity, so scrubbing
    // does not ask for a slightly different timestamp on every frame and miss
    // the cache every time.
    final snapped = (widget.sourceTimeUs ~/ 200000) * 200000;
    final key = '${clip.id}_$snapped';
    if (key == _stillKey) return;

    _loading = true;
    try {
      final path = clip.isImage
          ? _localPath(clip.uri)
          : await Thumbnailer.frameAt(clip, snapped);
      if (path == null || !mounted) return;

      final bytes = await File(path).readAsBytes();
      final codec = await ui.instantiateImageCodec(bytes);
      final frame = await codec.getNextFrame();
      if (!mounted) {
        frame.image.dispose();
        return;
      }
      setState(() {
        _still?.dispose();
        _still = frame.image;
        _stillKey = key;
      });
    } catch (_) {
      // A frame that will not decode leaves the previous one on screen, which
      // is less jarring than blanking the canvas mid-scrub.
    } finally {
      _loading = false;
    }
  }

  @override
  void dispose() {
    _still?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final clip = widget.clip;
    final adjustments = clip?.adjustments ?? const Adjustments();

    return ColoredBox(
      color: Color(widget.backgroundColor),
      child: Center(
        child: AspectRatio(
          aspectRatio: widget.aspect.ratio,
          child: ClipRect(child: _content(clip, adjustments)),
        ),
      ),
    );
  }

  Widget _content(TimelineClip? clip, Adjustments adjustments) {
    if (clip == null) {
      return const ColoredBox(color: Colors.black);
    }

    final controller = widget.controller;
    if (widget.isPlaying && controller != null && controller.value.isInitialized) {
      return ColorFiltered(
        colorFilter: ColorFilter.matrix(colorMatrixFor(adjustments)),
        child: FittedBox(
          fit: BoxFit.contain,
          child: SizedBox(
            width: controller.value.size.width,
            height: controller.value.size.height,
            child: VideoPlayer(controller),
          ),
        ),
      );
    }

    final still = _still;
    if (still == null) {
      return const ColoredBox(
        color: Colors.black,
        child: Center(
          child: SizedBox(
            width: 22,
            height: 22,
            child: CircularProgressIndicator(strokeWidth: 2, color: aqua),
          ),
        ),
      );
    }

    return GradedImage(image: still, adjustments: adjustments);
  }

  static String _localPath(String uri) {
    final parsed = Uri.tryParse(uri);
    if (parsed != null && parsed.scheme == 'file') {
      return parsed.toFilePath(windows: false);
    }
    return uri;
  }
}

/// A 4x5 colour matrix approximating [a], for use over moving video.
///
/// A matrix is a linear map, so it can express exposure, contrast, brightness,
/// saturation and white balance exactly, and cannot express curves, per-band
/// HSL, or a look — those are the parts of the grade it necessarily drops.
/// The approximation exists only so playback is not obviously the wrong
/// colour; the paused frame is what grading is judged on.
List<double> colorMatrixFor(Adjustments a) {
  var m = _identity();

  if (a.temperature != 0 || a.tint != 0) {
    m = _multiply(
      _offsets(
        r: a.temperature * 0.16 + a.tint * 0.08,
        g: a.temperature * 0.02 - a.tint * 0.10,
        b: -a.temperature * 0.16 + a.tint * 0.08,
      ),
      m,
    );
  }

  if (a.exposure != 0) {
    final gain = _pow2(a.exposure);
    m = _multiply(_scale(gain), m);
  }

  if (a.contrast != 0) {
    // Contrast pivots on mid grey: out = (in - 0.5) * k + 0.5.
    final k = 1 + a.contrast;
    m = _multiply(_scaleWithOffset(k, 0.5 * (1 - k)), m);
  }

  if (a.brightness != 0) {
    m = _multiply(_offsets(r: a.brightness * 0.35, g: a.brightness * 0.35, b: a.brightness * 0.35), m);
  }

  if (a.saturation != 0) {
    m = _multiply(_saturation(1 + a.saturation), m);
  }

  // Offsets are expressed in 0..1 above and the matrix wants 0..255.
  return [
    m[0], m[1], m[2], 0, m[3] * 255,
    m[4], m[5], m[6], 0, m[7] * 255,
    m[8], m[9], m[10], 0, m[11] * 255,
    0, 0, 0, 1, 0,
  ];
}

/// Rows of [r, g, b, offset] for the three colour channels.
List<double> _identity() => [1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0];

List<double> _scale(double k) => [k, 0, 0, 0, 0, k, 0, 0, 0, 0, k, 0];

List<double> _scaleWithOffset(double k, double offset) =>
    [k, 0, 0, offset, 0, k, 0, offset, 0, 0, k, offset];

List<double> _offsets({required double r, required double g, required double b}) =>
    [1, 0, 0, r, 0, 1, 0, g, 0, 0, 1, b];

List<double> _saturation(double amount) {
  const lr = 0.2126;
  const lg = 0.7152;
  const lb = 0.0722;
  final inv = 1 - amount;
  return [
    lr * inv + amount, lg * inv, lb * inv, 0,
    lr * inv, lg * inv + amount, lb * inv, 0,
    lr * inv, lg * inv, lb * inv + amount, 0,
  ];
}

/// Applies [lhs] after [rhs].
List<double> _multiply(List<double> lhs, List<double> rhs) {
  final out = List<double>.filled(12, 0);
  for (var row = 0; row < 3; row++) {
    for (var col = 0; col < 3; col++) {
      var sum = 0.0;
      for (var k = 0; k < 3; k++) {
        sum += lhs[row * 4 + k] * rhs[k * 4 + col];
      }
      out[row * 4 + col] = sum;
    }
    var offset = lhs[row * 4 + 3];
    for (var k = 0; k < 3; k++) {
      offset += lhs[row * 4 + k] * rhs[k * 4 + 3];
    }
    out[row * 4 + 3] = offset;
  }
  return out;
}

double _pow2(double exponent) {
  var result = 1.0;
  var e = exponent;
  while (e >= 1) {
    result *= 2;
    e -= 1;
  }
  while (e <= -1) {
    result /= 2;
    e += 1;
  }
  // A cheap approximation of 2^f over the remaining fraction; exact enough for
  // a preview that is already only an approximation.
  return result * (1 + e * 0.6931 + e * e * 0.2402);
}
