import 'dart:math' as math;

/// Looks are pure RGB to RGB functions, baked into a 64³ LUT at runtime.
///
/// Nothing binary ships with the app, so every look stays readable and
/// editable in source and costs nothing in APK size. It also means one
/// definition serves both consumers: the preview shader samples the baked LUT,
/// and export writes the same LUT out as a .cube for FFmpeg's lut3d filter.
/// A look cannot render differently on screen and in the file, because there
/// is only one of it.
class FilterPreset {
  const FilterPreset(this.id, this.name, this.category, this.look);

  final String id;
  final String name;
  final FilterCategory category;

  /// Transforms `rgb` (each 0..1) in place.
  final void Function(List<double>) look;
}

enum FilterCategory {
  basic('Basic'),
  portrait('Portrait'),
  film('Film'),
  cinema('Cinema'),
  mono('B&W'),
  vibe('Vibe');

  const FilterCategory(this.label);

  final String label;
}

/// The colour operations the presets are composed from.
class Look {
  const Look._();

  static double clamp01(double v) => v < 0 ? 0 : (v > 1 ? 1 : v);

  static double luma(List<double> c) =>
      0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];

  static void contrast(List<double> c, double amount) {
    for (var i = 0; i < 3; i++) {
      c[i] = clamp01((c[i] - 0.5) * (1 + amount) + 0.5);
    }
  }

  static void saturate(List<double> c, double amount) {
    final l = luma(c);
    for (var i = 0; i < 3; i++) {
      c[i] = clamp01(l + (c[i] - l) * (1 + amount));
    }
  }

  static void gamma(List<double> c, double g) {
    for (var i = 0; i < 3; i++) {
      c[i] = clamp01(math.pow(math.max(c[i], 0.0), g).toDouble());
    }
  }

  /// Tints shadows and highlights in opposite directions.
  static void splitTone(
    List<double> c,
    List<double> shadow,
    List<double> highlight,
    double strength,
  ) {
    final l = luma(c);
    final hi = smoothstep(0.45, 1, l);
    final lo = 1 - smoothstep(0, 0.55, l);
    for (var i = 0; i < 3; i++) {
      c[i] = clamp01(
        c[i] + (shadow[i] - 0.5) * lo * strength + (highlight[i] - 0.5) * hi * strength,
      );
    }
  }

  static void mono(
    List<double> c, {
    double rw = 0.299,
    double gw = 0.587,
    double bw = 0.114,
  }) {
    final v = clamp01(c[0] * rw + c[1] * gw + c[2] * bw);
    c[0] = v;
    c[1] = v;
    c[2] = v;
  }

  static void temperature(List<double> c, double amount) {
    c[0] = clamp01(c[0] + amount * 0.14);
    c[2] = clamp01(c[2] - amount * 0.14);
  }

  /// Lifts the black point, the defining move of a faded or matte look.
  static void fade(List<double> c, double amount) {
    for (var i = 0; i < 3; i++) {
      c[i] = clamp01(c[i] * (1 - amount * 0.18) + amount * 0.16);
    }
  }

  static double smoothstep(double e0, double e1, double x) {
    final t = ((x - e0) / (e1 - e0)).clamp(0.0, 1.0);
    return t * t * (3 - 2 * t);
  }

  /// Filmic S-curve; `amount` of 0 is identity.
  static void sCurve(List<double> c, double amount) {
    for (var i = 0; i < 3; i++) {
      final x = c[i];
      final s = x * x * (3 - 2 * x);
      c[i] = clamp01(x + (s - x) * amount);
    }
  }

  static void channelMix(List<double> c, double rr, double gg, double bb) {
    c[0] = clamp01(c[0] * rr);
    c[1] = clamp01(c[1] * gg);
    c[2] = clamp01(c[2] * bb);
  }
}

class Filters {
  const Filters._();

  static const String noneId = 'none';

  static final List<FilterPreset> all = [
    const FilterPreset(noneId, 'Original', FilterCategory.basic, _original),

    const FilterPreset('crisp', 'Crisp', FilterCategory.basic, _crisp),
    const FilterPreset('soft', 'Soft', FilterCategory.basic, _soft),
    const FilterPreset('punch', 'Punch', FilterCategory.basic, _punch),

    const FilterPreset('natural', 'Natural', FilterCategory.portrait, _natural),
    const FilterPreset('glow', 'Glow', FilterCategory.portrait, _glowLook),
    const FilterPreset('honey', 'Honey', FilterCategory.portrait, _honey),
    const FilterPreset(
        'porcelain', 'Porcelain', FilterCategory.portrait, _porcelain),

    const FilterPreset('kodachrome', 'Kodak', FilterCategory.film, _kodachrome),
    const FilterPreset('portra', 'Portra', FilterCategory.film, _portra),
    const FilterPreset('fuji', 'Fuji', FilterCategory.film, _fuji),
    const FilterPreset('expired', 'Expired', FilterCategory.film, _expired),

    const FilterPreset(
        'teal_orange', 'Blockbuster', FilterCategory.cinema, _tealOrange),
    const FilterPreset('noir_blue', 'Midnight', FilterCategory.cinema, _noirBlue),
    const FilterPreset('desert', 'Desert', FilterCategory.cinema, _desert),
    const FilterPreset('neon', 'Neon', FilterCategory.cinema, _neon),

    const FilterPreset('mono', 'Mono', FilterCategory.mono, _mono),
    const FilterPreset('mono_hard', 'High key', FilterCategory.mono, _monoHard),
    const FilterPreset('mono_soft', 'Silver', FilterCategory.mono, _monoSoft),
    const FilterPreset('sepia', 'Sepia', FilterCategory.mono, _sepia),

    const FilterPreset('vhs', 'VHS', FilterCategory.vibe, _vhs),
    const FilterPreset('dream', 'Dream', FilterCategory.vibe, _dream),
    const FilterPreset('cotton', 'Cotton', FilterCategory.vibe, _cotton),
    const FilterPreset('acid', 'Acid', FilterCategory.vibe, _acid),
  ];

  static final Map<String, FilterPreset> byId = {
    for (final preset in all) preset.id: preset,
  };

  static FilterPreset get(String id) => byId[id] ?? all.first;

  static List<FilterPreset> inCategory(FilterCategory category) =>
      all.where((preset) => preset.category == category).toList();
}

void _original(List<double> c) {}

void _crisp(List<double> c) {
  Look.contrast(c, 0.14);
  Look.saturate(c, 0.10);
  Look.sCurve(c, 0.25);
}

void _soft(List<double> c) {
  Look.contrast(c, -0.10);
  Look.fade(c, 0.35);
  Look.saturate(c, -0.05);
}

void _punch(List<double> c) {
  Look.contrast(c, 0.28);
  Look.saturate(c, 0.30);
  Look.sCurve(c, 0.35);
}

void _natural(List<double> c) {
  Look.temperature(c, 0.10);
  Look.saturate(c, 0.06);
  Look.sCurve(c, 0.15);
}

void _glowLook(List<double> c) {
  Look.temperature(c, 0.14);
  Look.fade(c, 0.20);
  Look.saturate(c, 0.08);
  Look.splitTone(c, const [0.5, 0.5, 0.54], const [0.56, 0.52, 0.46], 0.35);
}

void _honey(List<double> c) {
  Look.temperature(c, 0.22);
  Look.contrast(c, 0.08);
  Look.splitTone(c, const [0.48, 0.49, 0.55], const [0.60, 0.53, 0.42], 0.5);
}

void _porcelain(List<double> c) {
  Look.saturate(c, -0.18);
  Look.fade(c, 0.28);
  Look.contrast(c, 0.06);
  Look.temperature(c, 0.06);
}

void _kodachrome(List<double> c) {
  Look.contrast(c, 0.18);
  Look.saturate(c, 0.22);
  Look.channelMix(c, 1.04, 0.99, 0.92);
  Look.splitTone(c, const [0.47, 0.50, 0.56], const [0.57, 0.52, 0.44], 0.35);
}

void _portra(List<double> c) {
  Look.fade(c, 0.22);
  Look.saturate(c, -0.06);
  Look.temperature(c, 0.12);
  Look.splitTone(c, const [0.49, 0.50, 0.53], const [0.55, 0.51, 0.47], 0.4);
}

void _fuji(List<double> c) {
  Look.saturate(c, 0.14);
  Look.contrast(c, 0.12);
  Look.channelMix(c, 0.97, 1.03, 1.02);
}

void _expired(List<double> c) {
  Look.fade(c, 0.45);
  Look.saturate(c, -0.22);
  Look.splitTone(c, const [0.54, 0.50, 0.44], const [0.48, 0.52, 0.55], 0.55);
  Look.contrast(c, -0.06);
}

void _tealOrange(List<double> c) {
  Look.contrast(c, 0.16);
  Look.splitTone(c, const [0.42, 0.50, 0.60], const [0.60, 0.51, 0.40], 0.7);
  Look.saturate(c, 0.08);
}

void _noirBlue(List<double> c) {
  Look.contrast(c, 0.22);
  Look.saturate(c, -0.30);
  Look.splitTone(c, const [0.44, 0.48, 0.60], const [0.50, 0.51, 0.55], 0.6);
  Look.gamma(c, 1.12);
}

void _desert(List<double> c) {
  Look.temperature(c, 0.20);
  Look.saturate(c, -0.08);
  Look.contrast(c, 0.10);
  Look.splitTone(c, const [0.52, 0.50, 0.46], const [0.58, 0.53, 0.44], 0.45);
}

void _neon(List<double> c) {
  Look.contrast(c, 0.20);
  Look.saturate(c, 0.35);
  Look.splitTone(c, const [0.46, 0.46, 0.62], const [0.62, 0.46, 0.58], 0.6);
}

void _mono(List<double> c) {
  Look.mono(c);
  Look.contrast(c, 0.12);
}

void _monoHard(List<double> c) {
  Look.mono(c);
  Look.contrast(c, 0.42);
  Look.sCurve(c, 0.4);
}

void _monoSoft(List<double> c) {
  Look.mono(c);
  Look.fade(c, 0.35);
  Look.contrast(c, -0.05);
}

void _sepia(List<double> c) {
  Look.mono(c);
  c[0] = Look.clamp01(c[0] * 1.12 + 0.04);
  c[1] = Look.clamp01(c[1] * 1.00 + 0.01);
  c[2] = Look.clamp01(c[2] * 0.82);
}

void _vhs(List<double> c) {
  Look.fade(c, 0.4);
  Look.saturate(c, 0.18);
  Look.splitTone(c, const [0.54, 0.48, 0.56], const [0.48, 0.54, 0.52], 0.5);
}

void _dream(List<double> c) {
  Look.fade(c, 0.5);
  Look.saturate(c, -0.12);
  Look.temperature(c, 0.08);
  Look.gamma(c, 0.92);
}

void _cotton(List<double> c) {
  Look.fade(c, 0.32);
  Look.splitTone(c, const [0.55, 0.49, 0.53], const [0.53, 0.51, 0.56], 0.6);
  Look.saturate(c, 0.05);
}

void _acid(List<double> c) {
  Look.saturate(c, 0.55);
  Look.contrast(c, 0.24);
  Look.channelMix(c, 1.05, 1.02, 0.9);
}
