import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../core/color_matrix.dart';

enum BgMode {
  transparent('Transparent', Icons.grid_on_rounded),
  color('Couleur', Icons.format_color_fill_rounded),
  blur('Flou', Icons.blur_on_rounded),
  mono('Noir et blanc', Icons.contrast_rounded),
  photo('Photo', Icons.image_rounded);

  const BgMode(this.label, this.icon);
  final String label;
  final IconData icon;
}

enum BrushMode { erase, restore }

/// A brush stroke in image pixels.
@immutable
class MaskStroke {
  const MaskStroke(this.points, this.width, this.hardness, this.mode);
  final List<Offset> points;
  final double width;
  final double hardness;
  final BrushMode mode;

  MaskStroke adding(Offset p) => MaskStroke([...points, p], width, hardness, mode);
}

/// Everything the user can undo. Sizes are fractions of the photo's longest side
/// so preview and full-resolution export match exactly.
@immutable
class CutoutDoc {
  const CutoutDoc({
    this.threshold = 0.5,
    this.softness = 0.2,
    this.feather = 0.0015,
    this.regions = const [],
    this.strokes = const [],
    this.bg = BgMode.transparent,
    this.bgColor = const Color(0xFFFFFFFF),
    this.bgBlur = 0.02,
    this.outline = 0,
    this.outlineColor = const Color(0xFFFFFFFF),
    this.shadow = false,
    this.shadowBlur = 0.015,
    this.shadowOffset = 0.012,
    this.shadowOpacity = 0.45,
  });

  final double threshold;
  final double softness;
  final double feather;
  final List<Uint8List> regions;
  final List<MaskStroke> strokes;
  final BgMode bg;
  final Color bgColor;
  final double bgBlur;
  final double outline;
  final Color outlineColor;
  final bool shadow;
  final double shadowBlur;
  final double shadowOffset;
  final double shadowOpacity;

  CutoutDoc copyWith({
    double? threshold,
    double? softness,
    double? feather,
    List<Uint8List>? regions,
    List<MaskStroke>? strokes,
    BgMode? bg,
    Color? bgColor,
    double? bgBlur,
    double? outline,
    Color? outlineColor,
    bool? shadow,
    double? shadowBlur,
    double? shadowOffset,
    double? shadowOpacity,
  }) =>
      CutoutDoc(
        threshold: threshold ?? this.threshold,
        softness: softness ?? this.softness,
        feather: feather ?? this.feather,
        regions: regions ?? this.regions,
        strokes: strokes ?? this.strokes,
        bg: bg ?? this.bg,
        bgColor: bgColor ?? this.bgColor,
        bgBlur: bgBlur ?? this.bgBlur,
        outline: outline ?? this.outline,
        outlineColor: outlineColor ?? this.outlineColor,
        shadow: shadow ?? this.shadow,
        shadowBlur: shadowBlur ?? this.shadowBlur,
        shadowOffset: shadowOffset ?? this.shadowOffset,
        shadowOpacity: shadowOpacity ?? this.shadowOpacity,
      );
}

/// Everything needed to draw one frame.
class CutoutScene {
  CutoutScene({required this.photo, required this.mask, required this.doc, this.bgPhoto, this.live, this.showOriginal = false});
  final ui.Image photo;

  /// Alpha mask image; null means "keep everything" (manual mode).
  final ui.Image? mask;
  final CutoutDoc doc;
  final ui.Image? bgPhoto;
  final MaskStroke? live;
  final bool showOriginal;

  double get unit => math.max(photo.width, photo.height).toDouble();
}

void paintCover(Canvas canvas, ui.Image img, Rect dst) {
  final iw = img.width.toDouble();
  final ih = img.height.toDouble();
  final s = math.max(dst.width / iw, dst.height / ih);
  final sw = dst.width / s;
  final sh = dst.height / s;
  final src = Rect.fromLTWH((iw - sw) / 2, (ih - sh) / 2, sw, sh);
  canvas.drawImageRect(img, src, dst, Paint()..filterQuality = FilterQuality.medium);
}

void _paintStroke(Canvas canvas, MaskStroke s) {
  final paint = Paint()
    ..color = const Color(0xFFFFFFFF)
    ..blendMode = s.mode == BrushMode.erase ? BlendMode.dstOut : BlendMode.srcOver
    ..strokeCap = StrokeCap.round
    ..strokeJoin = StrokeJoin.round
    ..strokeWidth = s.width;
  if (s.hardness < 0.98) {
    paint.maskFilter = MaskFilter.blur(BlurStyle.normal, s.width * (1 - s.hardness) * 0.35);
  }
  if (s.points.length == 1) {
    canvas.drawCircle(s.points.first, s.width / 2, paint..style = PaintingStyle.fill);
    return;
  }
  final path = Path()..moveTo(s.points.first.dx, s.points.first.dy);
  for (var i = 1; i < s.points.length - 1; i++) {
    final a = s.points[i];
    final b = s.points[i + 1];
    path.quadraticBezierTo(a.dx, a.dy, (a.dx + b.dx) / 2, (a.dy + b.dy) / 2);
  }
  path.lineTo(s.points.last.dx, s.points.last.dy);
  canvas.drawPath(path, paint..style = PaintingStyle.stroke);
}

void _paintMaskAlpha(Canvas canvas, CutoutScene s, Rect rect) {
  final d = s.doc;
  final mask = s.mask;
  if (mask != null) {
    final f = d.feather * s.unit;
    canvas.drawImage(
      mask,
      Offset.zero,
      Paint()
        ..filterQuality = FilterQuality.low
        ..imageFilter = f > 0.3 ? ui.ImageFilter.blur(sigmaX: f, sigmaY: f) : null,
    );
  } else {
    canvas.drawRect(rect, Paint()..color = const Color(0xFFFFFFFF));
  }
  for (final st in d.strokes) {
    _paintStroke(canvas, st);
  }
  if (s.live != null) _paintStroke(canvas, s.live!);
}

/// Composites background, shadow, outline and the cut-out subject.
/// Coordinates are photo pixels; callers scale the canvas for preview.
void paintCutout(Canvas canvas, CutoutScene s) {
  final w = s.photo.width.toDouble();
  final h = s.photo.height.toDouble();
  final rect = Rect.fromLTWH(0, 0, w, h);
  final quality = Paint()..filterQuality = FilterQuality.medium;
  if (s.showOriginal) {
    canvas.drawImage(s.photo, Offset.zero, quality);
    return;
  }
  final d = s.doc;
  final u = s.unit;
  switch (d.bg) {
    case BgMode.transparent:
      break;
    case BgMode.color:
      canvas.drawRect(rect, Paint()..color = d.bgColor);
    case BgMode.blur:
      final sigma = math.max(0.5, d.bgBlur * u);
      canvas.save();
      canvas.clipRect(rect);
      canvas.drawImage(
        s.photo,
        Offset.zero,
        Paint()
          ..filterQuality = FilterQuality.medium
          ..imageFilter = ui.ImageFilter.blur(sigmaX: sigma, sigmaY: sigma, tileMode: TileMode.clamp),
      );
      canvas.restore();
    case BgMode.mono:
      canvas.drawImage(s.photo, Offset.zero, Paint()..colorFilter = ColorFilter.matrix(ColorMatrix.grayscale(1)));
    case BgMode.photo:
      if (s.bgPhoto != null) {
        paintCover(canvas, s.bgPhoto!, rect);
      } else {
        canvas.drawRect(rect, Paint()..color = d.bgColor);
      }
  }

  final pad = rect.inflate(u * 0.1);
  if (d.shadow) {
    final b = math.max(0.5, d.shadowBlur * u);
    canvas.saveLayer(
      pad,
      Paint()
        ..imageFilter = ui.ImageFilter.blur(sigmaX: b, sigmaY: b)
        ..colorFilter = ColorFilter.mode(Color.fromRGBO(0, 0, 0, d.shadowOpacity), BlendMode.srcIn),
    );
    canvas.translate(d.shadowOffset * u * 0.5, d.shadowOffset * u);
    _paintMaskAlpha(canvas, s, rect);
    canvas.restore();
  }

  if (d.outline > 0) {
    final r = math.max(0.5, d.outline * u);
    canvas.saveLayer(
      pad,
      Paint()
        ..imageFilter = ui.ImageFilter.compose(
          outer: ui.ImageFilter.blur(sigmaX: 0.6, sigmaY: 0.6),
          inner: ui.ImageFilter.dilate(radiusX: r, radiusY: r),
        )
        ..colorFilter = ColorFilter.mode(d.outlineColor, BlendMode.srcIn),
    );
    _paintMaskAlpha(canvas, s, rect);
    canvas.restore();
  }

  canvas.saveLayer(rect, Paint());
  _paintMaskAlpha(canvas, s, rect);
  canvas.drawImage(s.photo, Offset.zero, Paint()
    ..blendMode = BlendMode.srcIn
    ..filterQuality = FilterQuality.medium);
  canvas.restore();
}
