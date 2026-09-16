import 'dart:math' as math;

import 'ids.dart';

enum OverlayAnimation {
  none('None'),
  fade('Fade'),
  slideUp('Slide up'),
  slideDown('Slide down'),
  pop('Pop'),
  typewriter('Typewriter');

  const OverlayAnimation(this.label);

  final String label;
}

/// Placement of an overlay, as fractions of the canvas.
///
/// Fractions rather than pixels so a project opens correctly at any export
/// resolution: text positioned on a 1080p preview lands in the same place in a
/// 4K render without being rescaled by hand.
class OverlayTransform {
  const OverlayTransform({
    this.x = 0.5,
    this.y = 0.5,
    this.scale = 1,
    this.rotationDegrees = 0,
    this.opacity = 1,
  });

  final double x;
  final double y;
  final double scale;
  final double rotationDegrees;
  final double opacity;

  OverlayTransform copyWith({
    double? x,
    double? y,
    double? scale,
    double? rotationDegrees,
    double? opacity,
  }) =>
      OverlayTransform(
        x: x ?? this.x,
        y: y ?? this.y,
        scale: scale ?? this.scale,
        rotationDegrees: rotationDegrees ?? this.rotationDegrees,
        opacity: opacity ?? this.opacity,
      );

  Map<String, dynamic> toJson() => {
        'x': x,
        'y': y,
        'scale': scale,
        'rotationDegrees': rotationDegrees,
        'opacity': opacity,
      };

  static OverlayTransform fromJson(Map<String, dynamic> json) =>
      OverlayTransform(
        x: (json['x'] as num?)?.toDouble() ?? 0.5,
        y: (json['y'] as num?)?.toDouble() ?? 0.5,
        scale: (json['scale'] as num?)?.toDouble() ?? 1,
        rotationDegrees: (json['rotationDegrees'] as num?)?.toDouble() ?? 0,
        opacity: (json['opacity'] as num?)?.toDouble() ?? 1,
      );
}

abstract class Overlay {
  const Overlay();

  String get id;
  int get startUs;
  int get endUs;
  OverlayTransform get transform;
  OverlayAnimation get animationIn;
  OverlayAnimation get animationOut;
  int get animationDurationUs;

  int get durationUs => math.max(0, endUs - startUs);

  bool isActiveAt(int timeUs) => timeUs >= startUs && timeUs < endUs;

  /// Progress through the entry animation; 1 once it has finished.
  double enterProgress(int timeUs) {
    if (animationDurationUs <= 0 || animationIn == OverlayAnimation.none) {
      return 1;
    }
    final elapsed = timeUs - startUs;
    if (elapsed >= animationDurationUs) return 1;
    return (elapsed / animationDurationUs).clamp(0.0, 1.0);
  }

  /// Progress through the exit animation; 1 while it has not begun.
  double exitProgress(int timeUs) {
    if (animationDurationUs <= 0 || animationOut == OverlayAnimation.none) {
      return 1;
    }
    final remaining = endUs - timeUs;
    if (remaining >= animationDurationUs) return 1;
    return (remaining / animationDurationUs).clamp(0.0, 1.0);
  }

  String get trackLabel;

  Overlay withTiming({int? startUs, int? endUs});

  Overlay withTransform(OverlayTransform transform);

  Map<String, dynamic> toJson();
}

class TextStyleSpec {
  const TextStyleSpec({
    this.fontSize = 0.07, // fraction of canvas height
    this.color = 0xFFFFFFFF,
    this.bold = true,
    this.italic = false,
    this.align = TextAlignSpec.center,
    this.outlineWidth = 0.14, // fraction of font size
    this.outlineColor = 0xFF000000,
    this.plateColor = 0x00000000,
    this.letterSpacing = 0,
    this.lineHeight = 1.15,
  });

  final double fontSize;
  final int color;
  final bool bold;
  final bool italic;
  final TextAlignSpec align;
  final double outlineWidth;
  final int outlineColor;
  final int plateColor;
  final double letterSpacing;
  final double lineHeight;

  TextStyleSpec copyWith({
    double? fontSize,
    int? color,
    bool? bold,
    bool? italic,
    TextAlignSpec? align,
    double? outlineWidth,
    int? outlineColor,
    int? plateColor,
    double? letterSpacing,
    double? lineHeight,
  }) =>
      TextStyleSpec(
        fontSize: fontSize ?? this.fontSize,
        color: color ?? this.color,
        bold: bold ?? this.bold,
        italic: italic ?? this.italic,
        align: align ?? this.align,
        outlineWidth: outlineWidth ?? this.outlineWidth,
        outlineColor: outlineColor ?? this.outlineColor,
        plateColor: plateColor ?? this.plateColor,
        letterSpacing: letterSpacing ?? this.letterSpacing,
        lineHeight: lineHeight ?? this.lineHeight,
      );

  Map<String, dynamic> toJson() => {
        'fontSize': fontSize,
        'color': color,
        'bold': bold,
        'italic': italic,
        'align': align.name,
        'outlineWidth': outlineWidth,
        'outlineColor': outlineColor,
        'plateColor': plateColor,
        'letterSpacing': letterSpacing,
        'lineHeight': lineHeight,
      };

  static TextStyleSpec fromJson(Map<String, dynamic> json) => TextStyleSpec(
        fontSize: (json['fontSize'] as num?)?.toDouble() ?? 0.07,
        color: (json['color'] as num?)?.toInt() ?? 0xFFFFFFFF,
        bold: json['bold'] as bool? ?? true,
        italic: json['italic'] as bool? ?? false,
        align: TextAlignSpec.values.firstWhere(
          (a) => a.name == json['align'],
          orElse: () => TextAlignSpec.center,
        ),
        outlineWidth: (json['outlineWidth'] as num?)?.toDouble() ?? 0.14,
        outlineColor: (json['outlineColor'] as num?)?.toInt() ?? 0xFF000000,
        plateColor: (json['plateColor'] as num?)?.toInt() ?? 0x00000000,
        letterSpacing: (json['letterSpacing'] as num?)?.toDouble() ?? 0,
        lineHeight: (json['lineHeight'] as num?)?.toDouble() ?? 1.15,
      );
}

enum TextAlignSpec { left, center, right }

class TextOverlay extends Overlay {
  TextOverlay({
    String? id,
    this.text = 'Your text',
    this.style = const TextStyleSpec(),
    this.startUs = 0,
    this.endUs = 3000000,
    this.transform = const OverlayTransform(),
    this.animationIn = OverlayAnimation.fade,
    this.animationOut = OverlayAnimation.fade,
    this.animationDurationUs = 300000,
  }) : id = id ?? newId();

  @override
  final String id;
  final String text;
  final TextStyleSpec style;
  @override
  final int startUs;
  @override
  final int endUs;
  @override
  final OverlayTransform transform;
  @override
  final OverlayAnimation animationIn;
  @override
  final OverlayAnimation animationOut;
  @override
  final int animationDurationUs;

  @override
  String get trackLabel => text.trim().isEmpty ? 'Text' : text.trim();

  TextOverlay copyWith({
    String? text,
    TextStyleSpec? style,
    int? startUs,
    int? endUs,
    OverlayTransform? transform,
    OverlayAnimation? animationIn,
    OverlayAnimation? animationOut,
    int? animationDurationUs,
  }) =>
      TextOverlay(
        id: id,
        text: text ?? this.text,
        style: style ?? this.style,
        startUs: startUs ?? this.startUs,
        endUs: endUs ?? this.endUs,
        transform: transform ?? this.transform,
        animationIn: animationIn ?? this.animationIn,
        animationOut: animationOut ?? this.animationOut,
        animationDurationUs: animationDurationUs ?? this.animationDurationUs,
      );

  @override
  Overlay withTiming({int? startUs, int? endUs}) =>
      copyWith(startUs: startUs, endUs: endUs);

  @override
  Overlay withTransform(OverlayTransform transform) =>
      copyWith(transform: transform);

  @override
  Map<String, dynamic> toJson() => {
        'kind': 'text',
        'id': id,
        'text': text,
        'style': style.toJson(),
        'startUs': startUs,
        'endUs': endUs,
        'transform': transform.toJson(),
        'animationIn': animationIn.name,
        'animationOut': animationOut.name,
        'animationDurationUs': animationDurationUs,
      };
}

class StickerOverlay extends Overlay {
  StickerOverlay({
    String? id,
    this.emoji = '⭐',
    this.sizeFraction = 0.2,
    this.startUs = 0,
    this.endUs = 3000000,
    this.transform = const OverlayTransform(),
    this.animationIn = OverlayAnimation.pop,
    this.animationOut = OverlayAnimation.fade,
    this.animationDurationUs = 300000,
  }) : id = id ?? newId();

  @override
  final String id;
  final String emoji;

  /// Size as a fraction of canvas height.
  final double sizeFraction;
  @override
  final int startUs;
  @override
  final int endUs;
  @override
  final OverlayTransform transform;
  @override
  final OverlayAnimation animationIn;
  @override
  final OverlayAnimation animationOut;
  @override
  final int animationDurationUs;

  @override
  String get trackLabel => emoji;

  StickerOverlay copyWith({
    String? emoji,
    double? sizeFraction,
    int? startUs,
    int? endUs,
    OverlayTransform? transform,
    OverlayAnimation? animationIn,
    OverlayAnimation? animationOut,
    int? animationDurationUs,
  }) =>
      StickerOverlay(
        id: id,
        emoji: emoji ?? this.emoji,
        sizeFraction: sizeFraction ?? this.sizeFraction,
        startUs: startUs ?? this.startUs,
        endUs: endUs ?? this.endUs,
        transform: transform ?? this.transform,
        animationIn: animationIn ?? this.animationIn,
        animationOut: animationOut ?? this.animationOut,
        animationDurationUs: animationDurationUs ?? this.animationDurationUs,
      );

  @override
  Overlay withTiming({int? startUs, int? endUs}) =>
      copyWith(startUs: startUs, endUs: endUs);

  @override
  Overlay withTransform(OverlayTransform transform) =>
      copyWith(transform: transform);

  @override
  Map<String, dynamic> toJson() => {
        'kind': 'sticker',
        'id': id,
        'emoji': emoji,
        'sizeFraction': sizeFraction,
        'startUs': startUs,
        'endUs': endUs,
        'transform': transform.toJson(),
        'animationIn': animationIn.name,
        'animationOut': animationOut.name,
        'animationDurationUs': animationDurationUs,
      };
}

OverlayAnimation _animation(Object? name, OverlayAnimation fallback) =>
    OverlayAnimation.values.firstWhere(
      (a) => a.name == name,
      orElse: () => fallback,
    );

Overlay? overlayFromJson(Map<String, dynamic> json) {
  final transform = json['transform'] is Map
      ? OverlayTransform.fromJson(
          Map<String, dynamic>.from(json['transform'] as Map))
      : const OverlayTransform();
  final startUs = (json['startUs'] as num?)?.toInt() ?? 0;
  final endUs = (json['endUs'] as num?)?.toInt() ?? 3000000;
  final animationDurationUs =
      (json['animationDurationUs'] as num?)?.toInt() ?? 300000;

  switch (json['kind']) {
    case 'text':
      return TextOverlay(
        id: json['id'] as String?,
        text: json['text'] as String? ?? '',
        style: json['style'] is Map
            ? TextStyleSpec.fromJson(
                Map<String, dynamic>.from(json['style'] as Map))
            : const TextStyleSpec(),
        startUs: startUs,
        endUs: endUs,
        transform: transform,
        animationIn: _animation(json['animationIn'], OverlayAnimation.fade),
        animationOut: _animation(json['animationOut'], OverlayAnimation.fade),
        animationDurationUs: animationDurationUs,
      );
    case 'sticker':
      return StickerOverlay(
        id: json['id'] as String?,
        emoji: json['emoji'] as String? ?? '⭐',
        sizeFraction: (json['sizeFraction'] as num?)?.toDouble() ?? 0.2,
        startUs: startUs,
        endUs: endUs,
        transform: transform,
        animationIn: _animation(json['animationIn'], OverlayAnimation.pop),
        animationOut: _animation(json['animationOut'], OverlayAnimation.fade),
        animationDurationUs: animationDurationUs,
      );
    default:
      return null;
  }
}
