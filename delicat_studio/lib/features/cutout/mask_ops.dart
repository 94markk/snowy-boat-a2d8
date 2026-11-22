// Pure pixel operations for the background remover. No Flutter imports, so
// everything here can run inside `Isolate.run` and in plain unit tests.
import 'dart:math' as math;
import 'dart:typed_data';

/// Converts ML confidence (0..1 per pixel) into 8-bit alpha with a soft
/// threshold, then clears every pixel flagged in [erased].
Uint8List alphaFromConfidence(Float32List? confidence, int length, double threshold, double softness, Uint8List? erased) {
  final out = Uint8List(length);
  final lo = threshold - softness / 2;
  final hi = threshold + softness / 2;
  final span = math.max(1e-6, hi - lo);
  for (var i = 0; i < length; i++) {
    double a;
    if (confidence == null) {
      a = 1;
    } else {
      final c = confidence[i];
      if (c <= lo) {
        a = 0;
      } else if (c >= hi) {
        a = 1;
      } else {
        final t = (c - lo) / span;
        a = t * t * (3 - 2 * t);
      }
    }
    if (erased != null && erased[i] != 0) a = 0;
    out[i] = (a * 255).round();
  }
  return out;
}

/// Alpha-only RGBA (premultiplied-safe: colour equals alpha).
Uint8List alphaToRgba(Uint8List alpha) {
  final out = Uint8List(alpha.length * 4);
  for (var i = 0, j = 0; i < alpha.length; i++, j += 4) {
    final a = alpha[i];
    out[j] = a;
    out[j + 1] = a;
    out[j + 2] = a;
    out[j + 3] = a;
  }
  return out;
}

/// Combined pipeline used by the controller.
Uint8List buildMaskRgba(Float32List? confidence, int length, double threshold, double softness, Uint8List? erased) =>
    alphaToRgba(alphaFromConfidence(confidence, length, threshold, softness, erased));

/// Pixels whose colour is within [tolerance] (0..1) of the colour at the seed.
/// When [contiguous] is true only the connected area around the seed is kept.
Uint8List colorRegion(Uint8List rgba, int w, int h, int seedX, int seedY, double tolerance, bool contiguous) {
  final out = Uint8List(w * h);
  if (seedX < 0 || seedY < 0 || seedX >= w || seedY >= h) return out;
  final s = (seedY * w + seedX) * 4;
  final tr = rgba[s];
  final tg = rgba[s + 1];
  final tb = rgba[s + 2];
  final maxD = tolerance * 441.7;
  final maxD2 = maxD * maxD;

  bool match(int p) {
    final i = p * 4;
    final dr = rgba[i] - tr;
    final dg = rgba[i + 1] - tg;
    final db = rgba[i + 2] - tb;
    return dr * dr + dg * dg + db * db <= maxD2;
  }

  if (!contiguous) {
    for (var p = 0; p < w * h; p++) {
      if (match(p)) out[p] = 1;
    }
    return out;
  }

  // Scanline flood fill.
  final stack = <int>[seedY * w + seedX];
  while (stack.isNotEmpty) {
    final p = stack.removeLast();
    final y = p ~/ w;
    var x = p % w;
    if (out[p] != 0 || !match(p)) continue;
    while (x > 0 && out[y * w + x - 1] == 0 && match(y * w + x - 1)) {
      x--;
    }
    var spanUp = false;
    var spanDown = false;
    while (x < w) {
      final q = y * w + x;
      if (out[q] != 0 || !match(q)) break;
      out[q] = 1;
      if (y > 0) {
        final up = q - w;
        final m = out[up] == 0 && match(up);
        if (m && !spanUp) {
          stack.add(up);
          spanUp = true;
        } else if (!m) {
          spanUp = false;
        }
      }
      if (y < h - 1) {
        final dn = q + w;
        final m = out[dn] == 0 && match(dn);
        if (m && !spanDown) {
          stack.add(dn);
          spanDown = true;
        } else if (!m) {
          spanDown = false;
        }
      }
      x++;
    }
  }
  return out;
}

/// OR of all region masks.
Uint8List? unionRegions(List<Uint8List> regions, int length) {
  if (regions.isEmpty) return null;
  final out = Uint8List(length);
  for (final r in regions) {
    for (var i = 0; i < length; i++) {
      if (r[i] != 0) out[i] = 1;
    }
  }
  return out;
}

/// Packs 0/1 flags into bits (8 pixels per byte), so each undo step stays small.
Uint8List packBits(Uint8List flags) {
  final out = Uint8List((flags.length + 7) >> 3);
  for (var i = 0; i < flags.length; i++) {
    if (flags[i] != 0) out[i >> 3] |= 1 << (i & 7);
  }
  return out;
}

Uint8List unpackBits(Uint8List packed, int length) {
  final out = Uint8List(length);
  for (var i = 0; i < length; i++) {
    if (packed[i >> 3] & (1 << (i & 7)) != 0) out[i] = 1;
  }
  return out;
}

/// Adds the pixels of [flags] to a packed union (a fresh copy; [packed] is left untouched).
Uint8List mergePacked(Uint8List? packed, Uint8List flags) {
  final add = packBits(flags);
  if (packed == null) return add;
  final out = Uint8List.fromList(packed);
  for (var i = 0; i < out.length && i < add.length; i++) {
    out[i] |= add[i];
  }
  return out;
}

/// Bounding box (left, top, right, bottom) of alpha above [cutoff], or null.
List<int>? alphaBounds(Uint8List alpha, int w, int h, {int cutoff = 12}) {
  var l = w;
  var t = h;
  var r = -1;
  var b = -1;
  for (var y = 0; y < h; y++) {
    final row = y * w;
    for (var x = 0; x < w; x++) {
      if (alpha[row + x] > cutoff) {
        if (x < l) l = x;
        if (x > r) r = x;
        if (y < t) t = y;
        if (y > b) b = y;
      }
    }
  }
  if (r < 0) return null;
  return [l, t, r + 1, b + 1];
}

/// Bilinear resize of a confidence mask.
Float32List resampleMask(List<double> src, int sw, int sh, int dw, int dh) {
  final out = Float32List(dw * dh);
  for (var y = 0; y < dh; y++) {
    final fy = (y + 0.5) * sh / dh - 0.5;
    final y0 = fy.floor().clamp(0, sh - 1);
    final y1 = (y0 + 1).clamp(0, sh - 1);
    final wy = (fy - y0).clamp(0.0, 1.0);
    for (var x = 0; x < dw; x++) {
      final fx = (x + 0.5) * sw / dw - 0.5;
      final x0 = fx.floor().clamp(0, sw - 1);
      final x1 = (x0 + 1).clamp(0, sw - 1);
      final wx = (fx - x0).clamp(0.0, 1.0);
      final a = src[y0 * sw + x0] * (1 - wx) + src[y0 * sw + x1] * wx;
      final b = src[y1 * sw + x0] * (1 - wx) + src[y1 * sw + x1] * wx;
      out[y * dw + x] = a * (1 - wy) + b * wy;
    }
  }
  return out;
}
