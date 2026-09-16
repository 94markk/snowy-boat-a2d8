import 'dart:async';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import '../core/model/adjustments.dart';
import '../core/model/filters.dart';

/// Bakes every colour-only operation into one 64³ lookup table.
///
/// This is the single point where grading is defined. The preview shader
/// samples the table, and export writes the same table as a .cube for FFmpeg's
/// lut3d filter, so the screen and the file cannot disagree: there is one
/// implementation of the colour maths and two consumers of its output, rather
/// than two implementations that have to be kept in step by hand.
///
/// Only operations of the form `colour in, colour out` can live here. Blur,
/// sharpen, vignette, grain and glow depend on where a pixel sits, not what
/// colour it is, so they stay in the shader and map onto FFmpeg filters
/// separately.
class LutBaker {
  const LutBaker._();

  /// Edge length for export. 64 is the size real grading LUTs ship at.
  static const int exportSize = 64;

  /// Edge length for the preview.
  ///
  /// Baking is one evaluation per cube entry, so 64³ is a quarter of a million
  /// of them — far too slow to redo under a moving slider. 32³ is an eighth of
  /// the work and the difference is not visible at phone size, which is the
  /// only place this table is ever sampled.
  static const int previewSize = 32;

  /// Tiles across the strip. Eight keeps both sizes close to square, which
  /// matters only because a very wide, very short texture wastes more of a
  /// GPU's allocation granularity than a squarer one.
  static const int tilesAcross = 8;

  static int stripWidthFor(int size) => size * tilesAcross;

  static int stripHeightFor(int size) =>
      size * ((size + tilesAcross - 1) ~/ tilesAcross);

  /// Applies the full colour chain to one RGB triple, each 0..1.
  ///
  /// Order follows what a photographer expects, and the order matters: white
  /// balance before exposure, tonal ranges before contrast, vibrance before
  /// saturation so it can protect the pixels that are already saturated, and
  /// the look last so it sits on top of a corrected image rather than fighting
  /// the corrections.
  static List<double> gradePixel(Adjustments a, List<double> rgb) {
    final c = [rgb[0], rgb[1], rgb[2]];

    if (a.temperature != 0) {
      c[0] += a.temperature * 0.16;
      c[1] += a.temperature * 0.02;
      c[2] -= a.temperature * 0.16;
    }
    if (a.tint != 0) {
      c[0] += a.tint * 0.08;
      c[1] -= a.tint * 0.10;
      c[2] += a.tint * 0.08;
    }

    if (a.exposure != 0) {
      final gain = math.pow(2.0, a.exposure).toDouble();
      for (var i = 0; i < 3; i++) {
        c[i] *= gain;
      }
    }

    final l = Look.luma(c);
    if (a.highlights != 0) {
      final w = a.highlights * 0.45 * Look.smoothstep(0.45, 1, l);
      for (var i = 0; i < 3; i++) {
        c[i] += w;
      }
    }
    if (a.shadows != 0) {
      final w = a.shadows * 0.45 * (1 - Look.smoothstep(0, 0.55, l));
      for (var i = 0; i < 3; i++) {
        c[i] += w;
      }
    }
    if (a.whites != 0) {
      final w = a.whites * 0.30 * Look.smoothstep(0.65, 1, l);
      for (var i = 0; i < 3; i++) {
        c[i] += w;
      }
    }
    if (a.blacks != 0) {
      final w = a.blacks * 0.30 * (1 - Look.smoothstep(0, 0.35, l));
      for (var i = 0; i < 3; i++) {
        c[i] += w;
      }
    }

    if (a.contrast != 0) Look.contrast(c, a.contrast);
    if (a.brightness != 0) {
      for (var i = 0; i < 3; i++) {
        c[i] += a.brightness * 0.35;
      }
    }
    for (var i = 0; i < 3; i++) {
      c[i] = Look.clamp01(c[i]);
    }

    _applyCurves(a, c);

    if (a.hueShift != 0) {
      final hsl = _rgbToHsl(c);
      hsl[0] = (hsl[0] + a.hueShift / 360 + 1) % 1.0;
      _hslToRgb(hsl, c);
    }

    _applyHslBands(a, c);

    if (a.vibrance != 0) {
      final mx = math.max(c[0], math.max(c[1], c[2]));
      final mn = math.min(c[0], math.min(c[1], c[2]));
      final sat = mx - mn;
      final g = Look.luma(c);
      final amount = 1 + a.vibrance * (1 - sat);
      for (var i = 0; i < 3; i++) {
        c[i] = Look.clamp01(g + (c[i] - g) * amount);
      }
    }
    if (a.saturation != 0) Look.saturate(c, a.saturation);

    for (var i = 0; i < 3; i++) {
      c[i] = Look.clamp01(c[i]);
    }

    // The look, mixed in by its own strength.
    if (a.filterId != Filters.noneId && a.filterStrength > 0) {
      final looked = [c[0], c[1], c[2]];
      Filters.get(a.filterId).look(looked);
      final t = a.filterStrength.clamp(0.0, 1.0);
      for (var i = 0; i < 3; i++) {
        c[i] = c[i] + (looked[i] - c[i]) * t;
      }
    }

    if (a.fade > 0) {
      final t = a.fade.clamp(0.0, 1.0);
      for (var i = 0; i < 3; i++) {
        c[i] = c[i] + ((c[i] * 0.82 + 0.16) - c[i]) * t;
      }
    }

    for (var i = 0; i < 3; i++) {
      c[i] = Look.clamp01(c[i]);
    }
    return c;
  }

  static void _applyCurves(Adjustments a, List<double> c) {
    if (a.curveRed.isNotEmpty) c[0] = _curveAt(a.curveRed, c[0]);
    if (a.curveGreen.isNotEmpty) c[1] = _curveAt(a.curveGreen, c[1]);
    if (a.curveBlue.isNotEmpty) c[2] = _curveAt(a.curveBlue, c[2]);
    if (a.curveMaster.isNotEmpty) {
      for (var i = 0; i < 3; i++) {
        c[i] = _curveAt(a.curveMaster, c[i]);
      }
    }
  }

  /// Monotone cubic interpolation through the control points.
  ///
  /// Plain cubic splines overshoot between points, which on a tone curve means
  /// dragging one handle can lift or crush values either side of it — the
  /// curve stops doing what the shape says it does. The Fritsch-Carlson slope
  /// limit keeps it monotone, so the curve only ever goes where it is drawn.
  static double _curveAt(List<CurvePoint> points, double x) {
    if (points.isEmpty) return x;
    final sorted = [...points]..sort((a, b) => a.x.compareTo(b.x));
    if (x <= sorted.first.x) return sorted.first.y.clamp(0.0, 1.0);
    if (x >= sorted.last.x) return sorted.last.y.clamp(0.0, 1.0);
    if (sorted.length == 1) return sorted.first.y.clamp(0.0, 1.0);

    var i = 0;
    while (i < sorted.length - 2 && x > sorted[i + 1].x) {
      i++;
    }

    final p0 = sorted[i];
    final p1 = sorted[i + 1];
    final h = p1.x - p0.x;
    if (h <= 0) return p1.y.clamp(0.0, 1.0);

    final delta = (p1.y - p0.y) / h;
    final mPrev = i == 0
        ? delta
        : (p1.y - sorted[i - 1].y) / (p1.x - sorted[i - 1].x);
    final mNext = i + 2 >= sorted.length
        ? delta
        : (sorted[i + 2].y - p0.y) / (sorted[i + 2].x - p0.x);

    var m0 = (mPrev + delta) / 2;
    var m1 = (delta + mNext) / 2;
    if (delta == 0) {
      m0 = 0;
      m1 = 0;
    } else {
      m0 = m0.clamp(-3 * delta.abs(), 3 * delta.abs());
      m1 = m1.clamp(-3 * delta.abs(), 3 * delta.abs());
    }

    final t = (x - p0.x) / h;
    final t2 = t * t;
    final t3 = t2 * t;
    final y = (2 * t3 - 3 * t2 + 1) * p0.y +
        (t3 - 2 * t2 + t) * h * m0 +
        (-2 * t3 + 3 * t2) * p1.y +
        (t3 - t2) * h * m1;
    return y.clamp(0.0, 1.0);
  }

  static void _applyHslBands(Adjustments a, List<double> c) {
    var touched = false;
    for (var i = 0; i < 8; i++) {
      if (a.hslHue[i] != 0 || a.hslSat[i] != 0 || a.hslLum[i] != 0) {
        touched = true;
        break;
      }
    }
    if (!touched) return;

    final hsl = _rgbToHsl(c);
    final h = hsl[0] * 360;
    var dh = 0.0;
    var ds = 0.0;
    var dl = 0.0;

    for (var i = 0; i < 8; i++) {
      final centre = HslBand.values[i].centerHue;
      // Wrap to the shorter way round the wheel, so red at 355° is treated as
      // five degrees from the red band rather than three hundred and fifty.
      final dist = ((h - centre + 540) % 360 - 180).abs();
      var w = (1 - dist / 45).clamp(0.0, 1.0);
      w = w * w * (3 - 2 * w);
      dh += a.hslHue[i] * w;
      ds += a.hslSat[i] * w;
      dl += a.hslLum[i] * w;
    }

    hsl[0] = (hsl[0] + (dh * 30) / 360 + 1) % 1.0;
    hsl[1] = (hsl[1] * (1 + ds)).clamp(0.0, 1.0);
    hsl[2] = (hsl[2] + dl * 0.25).clamp(0.0, 1.0);
    _hslToRgb(hsl, c);
  }

  static List<double> _rgbToHsl(List<double> c) {
    final maxc = math.max(c[0], math.max(c[1], c[2]));
    final minc = math.min(c[0], math.min(c[1], c[2]));
    final l = (maxc + minc) * 0.5;
    var h = 0.0;
    var s = 0.0;
    final d = maxc - minc;
    if (d > 1e-5) {
      s = l > 0.5 ? d / (2 - maxc - minc) : d / (maxc + minc);
      if (maxc == c[0]) {
        h = (c[1] - c[2]) / d + (c[1] < c[2] ? 6 : 0);
      } else if (maxc == c[1]) {
        h = (c[2] - c[0]) / d + 2;
      } else {
        h = (c[0] - c[1]) / d + 4;
      }
      h /= 6;
    }
    return [h, s, l];
  }

  static double _hueToRgb(double p, double q, double tIn) {
    var t = tIn;
    if (t < 0) t += 1;
    if (t > 1) t -= 1;
    if (t < 1 / 6) return p + (q - p) * 6 * t;
    if (t < 1 / 2) return q;
    if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
    return p;
  }

  static void _hslToRgb(List<double> hsl, List<double> out) {
    final h = hsl[0];
    final s = hsl[1];
    final l = hsl[2];
    if (s < 1e-5) {
      out[0] = l;
      out[1] = l;
      out[2] = l;
      return;
    }
    final q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    final p = 2 * l - q;
    out[0] = _hueToRgb(p, q, h + 1 / 3);
    out[1] = _hueToRgb(p, q, h);
    out[2] = _hueToRgb(p, q, h - 1 / 3);
  }

  /// The table as an RGBA strip of NxN tiles, ready to upload as a texture.
  static Uint8List bakeStrip(Adjustments a, {int size = exportSize}) {
    final width = stripWidthFor(size);
    final height = stripHeightFor(size);
    final pixels = Uint8List(width * height * 4);
    final rgb = [0.0, 0.0, 0.0];
    final scale = size - 1;

    for (var b = 0; b < size; b++) {
      final tileX = (b % tilesAcross) * size;
      final tileY = (b ~/ tilesAcross) * size;
      for (var g = 0; g < size; g++) {
        for (var r = 0; r < size; r++) {
          rgb[0] = r / scale;
          rgb[1] = g / scale;
          rgb[2] = b / scale;
          final out = gradePixel(a, rgb);

          final i = ((tileY + g) * width + tileX + r) * 4;
          pixels[i] = (out[0] * 255).round().clamp(0, 255);
          pixels[i + 1] = (out[1] * 255).round().clamp(0, 255);
          pixels[i + 2] = (out[2] * 255).round().clamp(0, 255);
          pixels[i + 3] = 255;
        }
      }
    }
    return pixels;
  }

  /// Decodes the strip into an image the shader can sample.
  static Future<ui.Image> bakeImage(
    Adjustments a, {
    int size = previewSize,
  }) {
    final completer = Completer<ui.Image>();
    ui.decodeImageFromPixels(
      bakeStrip(a, size: size),
      stripWidthFor(size),
      stripHeightFor(size),
      ui.PixelFormat.rgba8888,
      completer.complete,
    );
    return completer.future;
  }

  /// The same table in Resolve .cube form, which FFmpeg's lut3d filter reads.
  ///
  /// .cube iterates red fastest and blue slowest. Getting that order wrong
  /// produces an export whose colour channels are transposed — the kind of
  /// failure that looks like a corrupted file rather than a wrong loop.
  static String bakeCube(Adjustments a, {int size = exportSize}) {
    final buffer = StringBuffer()
      ..writeln('# Generated by Delicat Studio')
      ..writeln('LUT_3D_SIZE $size')
      ..writeln('DOMAIN_MIN 0.0 0.0 0.0')
      ..writeln('DOMAIN_MAX 1.0 1.0 1.0');

    final rgb = [0.0, 0.0, 0.0];
    for (var b = 0; b < size; b++) {
      for (var g = 0; g < size; g++) {
        for (var r = 0; r < size; r++) {
          rgb[0] = r / (size - 1);
          rgb[1] = g / (size - 1);
          rgb[2] = b / (size - 1);
          final out = gradePixel(a, rgb);
          buffer.writeln(
            '${out[0].toStringAsFixed(6)} '
            '${out[1].toStringAsFixed(6)} '
            '${out[2].toStringAsFixed(6)}',
          );
        }
      }
    }
    return buffer.toString();
  }
}
