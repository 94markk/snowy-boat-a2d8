import 'dart:typed_data';

import 'filters.dart';

/// Every colour parameter the engine understands.
///
/// This class is the single source of truth. The UI builds its sliders from
/// [AdjustSpec.all], the grading shader binds uniforms from the same ids in
/// the same order, and export bakes its LUT from the identical struct. There
/// is no second path where a value can be read from somewhere the slider never
/// wrote to, which is what makes a control that appears to do nothing
/// impossible by construction rather than merely unlikely.
///
/// Values are normalised so that neutral is 0, except where noted.
class Adjustments {
  const Adjustments({
    this.exposure = 0, // EV, -3..3
    this.brightness = 0, // -1..1
    this.contrast = 0, // -1..1
    this.highlights = 0, // -1..1
    this.shadows = 0, // -1..1
    this.whites = 0, // -1..1
    this.blacks = 0, // -1..1
    this.saturation = 0, // -1..1, -1 is greyscale
    this.vibrance = 0, // -1..1, weighted towards muted tones
    this.temperature = 0, // -1..1, blue to amber
    this.tint = 0, // -1..1, green to magenta
    this.hueShift = 0, // -180..180 degrees
    this.sharpen = 0, // 0..1
    this.blur = 0, // 0..1, radius as a fraction of the short edge
    this.fade = 0, // 0..1, lifted blacks
    this.vignette = 0, // -1..1, negative brightens the edge
    this.grain = 0, // 0..1
    this.glow = 0, // 0..1, bloom on highlights
    this.hslHue = const [0, 0, 0, 0, 0, 0, 0, 0],
    this.hslSat = const [0, 0, 0, 0, 0, 0, 0, 0],
    this.hslLum = const [0, 0, 0, 0, 0, 0, 0, 0],
    this.curveMaster = const [],
    this.curveRed = const [],
    this.curveGreen = const [],
    this.curveBlue = const [],
    this.filterId = Filters.noneId,
    this.filterStrength = 1,
  });

  final double exposure;
  final double brightness;
  final double contrast;
  final double highlights;
  final double shadows;
  final double whites;
  final double blacks;

  final double saturation;
  final double vibrance;
  final double temperature;
  final double tint;
  final double hueShift;

  final double sharpen;
  final double blur;
  final double fade;
  final double vignette;
  final double grain;
  final double glow;

  /// Per-band HSL, indexed to match [HslBand.values].
  final List<double> hslHue;
  final List<double> hslSat;
  final List<double> hslLum;

  /// Tone curve control points per channel. Empty means identity.
  final List<CurvePoint> curveMaster;
  final List<CurvePoint> curveRed;
  final List<CurvePoint> curveGreen;
  final List<CurvePoint> curveBlue;

  final String filterId;
  final double filterStrength;

  /// True when nothing would change if this were skipped entirely.
  ///
  /// Worth knowing per clip: an untouched clip can take a copy path on export
  /// instead of a decode, grade and re-encode round trip.
  bool get isNeutral =>
      exposure == 0 &&
      brightness == 0 &&
      contrast == 0 &&
      highlights == 0 &&
      shadows == 0 &&
      whites == 0 &&
      blacks == 0 &&
      saturation == 0 &&
      vibrance == 0 &&
      temperature == 0 &&
      tint == 0 &&
      hueShift == 0 &&
      sharpen == 0 &&
      blur == 0 &&
      fade == 0 &&
      vignette == 0 &&
      grain == 0 &&
      glow == 0 &&
      !hslHue.any((v) => v != 0) &&
      !hslSat.any((v) => v != 0) &&
      !hslLum.any((v) => v != 0) &&
      curveMaster.isEmpty &&
      curveRed.isEmpty &&
      curveGreen.isEmpty &&
      curveBlue.isEmpty &&
      filterId == Filters.noneId;

  double get(String id) => switch (id) {
        'exposure' => exposure,
        'brightness' => brightness,
        'contrast' => contrast,
        'highlights' => highlights,
        'shadows' => shadows,
        'whites' => whites,
        'blacks' => blacks,
        'saturation' => saturation,
        'vibrance' => vibrance,
        'temperature' => temperature,
        'tint' => tint,
        'hueShift' => hueShift,
        'sharpen' => sharpen,
        'blur' => blur,
        'fade' => fade,
        'vignette' => vignette,
        'grain' => grain,
        'glow' => glow,
        'filterStrength' => filterStrength,
        _ => 0,
      };

  Adjustments set(String id, double value) => switch (id) {
        'exposure' => copyWith(exposure: value),
        'brightness' => copyWith(brightness: value),
        'contrast' => copyWith(contrast: value),
        'highlights' => copyWith(highlights: value),
        'shadows' => copyWith(shadows: value),
        'whites' => copyWith(whites: value),
        'blacks' => copyWith(blacks: value),
        'saturation' => copyWith(saturation: value),
        'vibrance' => copyWith(vibrance: value),
        'temperature' => copyWith(temperature: value),
        'tint' => copyWith(tint: value),
        'hueShift' => copyWith(hueShift: value),
        'sharpen' => copyWith(sharpen: value),
        'blur' => copyWith(blur: value),
        'fade' => copyWith(fade: value),
        'vignette' => copyWith(vignette: value),
        'grain' => copyWith(grain: value),
        'glow' => copyWith(glow: value),
        'filterStrength' => copyWith(filterStrength: value),
        _ => this,
      };

  Adjustments setHsl(HslBand band, {double? hue, double? sat, double? lum}) {
    List<double> replace(List<double> source, double? value) {
      if (value == null) return source;
      final next = List<double>.from(source);
      next[band.index] = value;
      return next;
    }

    return copyWith(
      hslHue: replace(hslHue, hue),
      hslSat: replace(hslSat, sat),
      hslLum: replace(hslLum, lum),
    );
  }

  Adjustments copyWith({
    double? exposure,
    double? brightness,
    double? contrast,
    double? highlights,
    double? shadows,
    double? whites,
    double? blacks,
    double? saturation,
    double? vibrance,
    double? temperature,
    double? tint,
    double? hueShift,
    double? sharpen,
    double? blur,
    double? fade,
    double? vignette,
    double? grain,
    double? glow,
    List<double>? hslHue,
    List<double>? hslSat,
    List<double>? hslLum,
    List<CurvePoint>? curveMaster,
    List<CurvePoint>? curveRed,
    List<CurvePoint>? curveGreen,
    List<CurvePoint>? curveBlue,
    String? filterId,
    double? filterStrength,
  }) {
    return Adjustments(
      exposure: exposure ?? this.exposure,
      brightness: brightness ?? this.brightness,
      contrast: contrast ?? this.contrast,
      highlights: highlights ?? this.highlights,
      shadows: shadows ?? this.shadows,
      whites: whites ?? this.whites,
      blacks: blacks ?? this.blacks,
      saturation: saturation ?? this.saturation,
      vibrance: vibrance ?? this.vibrance,
      temperature: temperature ?? this.temperature,
      tint: tint ?? this.tint,
      hueShift: hueShift ?? this.hueShift,
      sharpen: sharpen ?? this.sharpen,
      blur: blur ?? this.blur,
      fade: fade ?? this.fade,
      vignette: vignette ?? this.vignette,
      grain: grain ?? this.grain,
      glow: glow ?? this.glow,
      hslHue: hslHue ?? this.hslHue,
      hslSat: hslSat ?? this.hslSat,
      hslLum: hslLum ?? this.hslLum,
      curveMaster: curveMaster ?? this.curveMaster,
      curveRed: curveRed ?? this.curveRed,
      curveGreen: curveGreen ?? this.curveGreen,
      curveBlue: curveBlue ?? this.curveBlue,
      filterId: filterId ?? this.filterId,
      filterStrength: filterStrength ?? this.filterStrength,
    );
  }

  /// Scalars in the exact order the shader declares its uniforms.
  ///
  /// The shader reads uniforms by slot, not by name, so this ordering is load
  /// bearing: it must match `shaders/color_grade.frag` exactly. Both are
  /// generated from [AdjustSpec.all] for that reason, and the test in
  /// `test/shader_uniforms_test.dart` fails if they drift apart.
  Float32List toUniforms() {
    final values = Float32List(AdjustSpec.all.length);
    for (var i = 0; i < AdjustSpec.all.length; i++) {
      values[i] = get(AdjustSpec.all[i].id);
    }
    return values;
  }

  Map<String, dynamic> toJson() => {
        for (final spec in AdjustSpec.all) spec.id: get(spec.id),
        'hslHue': hslHue,
        'hslSat': hslSat,
        'hslLum': hslLum,
        'curveMaster': curveMaster.map((p) => p.toJson()).toList(),
        'curveRed': curveRed.map((p) => p.toJson()).toList(),
        'curveGreen': curveGreen.map((p) => p.toJson()).toList(),
        'curveBlue': curveBlue.map((p) => p.toJson()).toList(),
        'filterId': filterId,
        'filterStrength': filterStrength,
      };

  static Adjustments fromJson(Map<String, dynamic> json) {
    var result = const Adjustments();
    for (final spec in AdjustSpec.all) {
      final value = json[spec.id];
      if (value is num) result = result.set(spec.id, value.toDouble());
    }

    List<double> bands(String key) {
      final raw = json[key];
      if (raw is! List) return const [0, 0, 0, 0, 0, 0, 0, 0];
      return List<double>.generate(
        8,
        (i) => i < raw.length && raw[i] is num ? (raw[i] as num).toDouble() : 0,
      );
    }

    List<CurvePoint> curve(String key) {
      final raw = json[key];
      if (raw is! List) return const [];
      return raw.whereType<Map>().map(CurvePoint.fromJson).toList();
    }

    return result.copyWith(
      hslHue: bands('hslHue'),
      hslSat: bands('hslSat'),
      hslLum: bands('hslLum'),
      curveMaster: curve('curveMaster'),
      curveRed: curve('curveRed'),
      curveGreen: curve('curveGreen'),
      curveBlue: curve('curveBlue'),
      filterId: json['filterId'] as String? ?? Filters.noneId,
      filterStrength: (json['filterStrength'] as num?)?.toDouble() ?? 1,
    );
  }

  @override
  bool operator ==(Object other) {
    if (identical(this, other)) return true;
    if (other is! Adjustments) return false;
    for (final spec in AdjustSpec.all) {
      if (get(spec.id) != other.get(spec.id)) return false;
    }
    return _sameList(hslHue, other.hslHue) &&
        _sameList(hslSat, other.hslSat) &&
        _sameList(hslLum, other.hslLum) &&
        _sameCurve(curveMaster, other.curveMaster) &&
        _sameCurve(curveRed, other.curveRed) &&
        _sameCurve(curveGreen, other.curveGreen) &&
        _sameCurve(curveBlue, other.curveBlue) &&
        filterId == other.filterId &&
        filterStrength == other.filterStrength;
  }

  @override
  int get hashCode => Object.hash(
        Object.hashAll([for (final s in AdjustSpec.all) get(s.id)]),
        Object.hashAll(hslHue),
        Object.hashAll(hslSat),
        Object.hashAll(hslLum),
        Object.hashAll(curveMaster),
        Object.hashAll(curveRed),
        Object.hashAll(curveGreen),
        Object.hashAll(curveBlue),
        filterId,
        filterStrength,
      );

  static bool _sameList(List<double> a, List<double> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) return false;
    }
    return true;
  }

  static bool _sameCurve(List<CurvePoint> a, List<CurvePoint> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i] != b[i]) return false;
    }
    return true;
  }
}

class CurvePoint {
  const CurvePoint(this.x, this.y);

  final double x;
  final double y;

  Map<String, dynamic> toJson() => {'x': x, 'y': y};

  static CurvePoint fromJson(Map<dynamic, dynamic> json) => CurvePoint(
        (json['x'] as num?)?.toDouble() ?? 0,
        (json['y'] as num?)?.toDouble() ?? 0,
      );

  @override
  bool operator ==(Object other) =>
      other is CurvePoint && other.x == x && other.y == y;

  @override
  int get hashCode => Object.hash(x, y);
}

enum HslBand {
  red('Red', 0),
  orange('Orange', 30),
  yellow('Yellow', 60),
  green('Green', 120),
  aqua('Aqua', 180),
  blue('Blue', 220),
  purple('Purple', 280),
  magenta('Magenta', 320);

  const HslBand(this.label, this.centerHue);

  final String label;
  final double centerHue;
}

enum AdjustGroup {
  light('Light'),
  colour('Color'),
  texture('Texture'),
  effect('Effect');

  const AdjustGroup(this.label);

  final String label;
}

/// UI description of one scalar parameter.
class AdjustSpec {
  const AdjustSpec(
    this.id,
    this.label,
    this.min,
    this.max,
    this.defaultValue,
    this.group, {
    this.displayScale = 100,
    this.unit = '',
  });

  final String id;
  final String label;
  final double min;
  final double max;
  final double defaultValue;
  final AdjustGroup group;

  /// The number shown to the user is `value * displayScale`.
  final double displayScale;
  final String unit;

  String display(double value) {
    final scaled = value * displayScale;
    final text = displayScale == 1
        ? scaled.toStringAsFixed(scaled.abs() < 10 ? 1 : 0)
        : scaled.round().toString();
    return '$text$unit';
  }

  /// Order is load bearing: the grading shader binds uniforms by slot in this
  /// same sequence. Adding a parameter means adding one entry here and one
  /// uniform read in the shader, and the slider appears on its own.
  static const List<AdjustSpec> all = [
    AdjustSpec('exposure', 'Exposure', -3, 3, 0, AdjustGroup.light,
        displayScale: 1, unit: ' EV'),
    AdjustSpec('brightness', 'Brightness', -1, 1, 0, AdjustGroup.light),
    AdjustSpec('contrast', 'Contrast', -1, 1, 0, AdjustGroup.light),
    AdjustSpec('highlights', 'Highlights', -1, 1, 0, AdjustGroup.light),
    AdjustSpec('shadows', 'Shadows', -1, 1, 0, AdjustGroup.light),
    AdjustSpec('whites', 'Whites', -1, 1, 0, AdjustGroup.light),
    AdjustSpec('blacks', 'Blacks', -1, 1, 0, AdjustGroup.light),
    AdjustSpec('saturation', 'Saturation', -1, 1, 0, AdjustGroup.colour),
    AdjustSpec('vibrance', 'Vibrance', -1, 1, 0, AdjustGroup.colour),
    AdjustSpec('temperature', 'Temperature', -1, 1, 0, AdjustGroup.colour),
    AdjustSpec('tint', 'Tint', -1, 1, 0, AdjustGroup.colour),
    AdjustSpec('hueShift', 'Hue', -180, 180, 0, AdjustGroup.colour,
        displayScale: 1, unit: '°'),
    AdjustSpec('sharpen', 'Sharpen', 0, 1, 0, AdjustGroup.texture),
    AdjustSpec('blur', 'Blur', 0, 1, 0, AdjustGroup.texture),
    AdjustSpec('grain', 'Grain', 0, 1, 0, AdjustGroup.texture),
    AdjustSpec('fade', 'Fade', 0, 1, 0, AdjustGroup.effect),
    AdjustSpec('vignette', 'Vignette', -1, 1, 0, AdjustGroup.effect),
    AdjustSpec('glow', 'Glow', 0, 1, 0, AdjustGroup.effect),
  ];

  static final Map<String, AdjustSpec> byId = {
    for (final spec in all) spec.id: spec,
  };

  static List<AdjustSpec> inGroup(AdjustGroup group) =>
      all.where((spec) => spec.group == group).toList();
}
