import 'dart:math' as math;

/// 4×5 colour matrices (row-major, offsets in 0..255) as used by
/// `ColorFilter.matrix`. All builders take values in -1..1 unless noted.
typedef Cm = List<double>;

abstract final class ColorMatrix {
  static const double _lr = 0.2126;
  static const double _lg = 0.7152;
  static const double _lb = 0.0722;

  static Cm identity() => <double>[1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 0];

  /// Returns the matrix that applies [first] then [then].
  static Cm compose(Cm first, Cm then) {
    final out = List<double>.filled(20, 0);
    for (var r = 0; r < 4; r++) {
      for (var c = 0; c < 5; c++) {
        var s = 0.0;
        for (var k = 0; k < 4; k++) {
          s += then[r * 5 + k] * first[k * 5 + c];
        }
        if (c == 4) s += then[r * 5 + 4];
        out[r * 5 + c] = s;
      }
    }
    return out;
  }

  static Cm chain(Iterable<Cm> ms) => ms.fold(identity(), compose);

  static Cm lerp(Cm a, Cm b, double t) => List<double>.generate(20, (i) => a[i] + (b[i] - a[i]) * t);

  static Cm _scaleOffset(double rs, double gs, double bs, double ro, double go, double bo) =>
      <double>[rs, 0, 0, 0, ro, 0, gs, 0, 0, go, 0, 0, bs, 0, bo, 0, 0, 0, 1, 0];

  static Cm brightness(double v) => _scaleOffset(1, 1, 1, v * 90, v * 90, v * 90);

  static Cm exposure(double v) {
    final s = math.pow(2, v * 1.2).toDouble();
    return _scaleOffset(s, s, s, 0, 0, 0);
  }

  static Cm contrast(double v) {
    final s = 1 + (v >= 0 ? v * 0.8 : v * 0.6);
    final o = 128 * (1 - s);
    return _scaleOffset(s, s, s, o, o, o);
  }

  static Cm saturation(double v) {
    final s = (1 + v).clamp(0.0, 3.0);
    final i = 1 - s;
    return <double>[
      _lr * i + s, _lg * i, _lb * i, 0, 0, //
      _lr * i, _lg * i + s, _lb * i, 0, 0,
      _lr * i, _lg * i, _lb * i + s, 0, 0,
      0, 0, 0, 1, 0,
    ];
  }

  static Cm warmth(double v) => _scaleOffset(1 + v * 0.08, 1, 1 - v * 0.08, v * 22, v * 6, -v * 26);

  static Cm tint(double v) => _scaleOffset(1, 1, 1, v * 12, -v * 20, v * 12);

  /// Lifts blacks and softens whites (0..1).
  static Cm fade(double v) {
    final s = 1 - 0.28 * v;
    final o = 255 * 0.16 * v;
    return _scaleOffset(s, s, s, o, o, o);
  }

  static Cm hue(double degrees) {
    final a = degrees * math.pi / 180;
    final c = math.cos(a);
    final s = math.sin(a);
    return <double>[
      0.213 + c * 0.787 - s * 0.213, 0.715 - c * 0.715 - s * 0.715, 0.072 - c * 0.072 + s * 0.928, 0, 0, //
      0.213 - c * 0.213 + s * 0.143, 0.715 + c * 0.285 + s * 0.140, 0.072 - c * 0.072 - s * 0.283, 0, 0,
      0.213 - c * 0.213 - s * 0.787, 0.715 - c * 0.715 + s * 0.715, 0.072 + c * 0.928 + s * 0.072, 0, 0,
      0, 0, 0, 1, 0,
    ];
  }

  /// Sepia toning (0..1).
  static Cm sepia(double v) => lerp(identity(), <double>[
        0.393, 0.769, 0.189, 0, 0, //
        0.349, 0.686, 0.168, 0, 0,
        0.272, 0.534, 0.131, 0, 0,
        0, 0, 0, 1, 0,
      ], v);

  /// Black and white (0..1).
  static Cm grayscale(double v) => saturation(-v);
}
