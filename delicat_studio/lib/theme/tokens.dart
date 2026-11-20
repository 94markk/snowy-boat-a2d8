// Delicat « Kat » design tokens: colours, type scale, spacing, radii, motion.
import 'package:material_ui/material_ui.dart';

/// Raw brand values. Screens use [KatColors] so light and dark stay in sync.
abstract final class KatPalette {
  static const indigo = Color(0xFF4B3FE0);
  static const fuchsia = Color(0xFFC8358F);
  static const sun = Color(0xFFF5B400);
  static const kat = Color(0xFF141834);
  static const paper = Color(0xFFF4F4F8);
  static const hairline = Color(0xFFEBEAF2);
  static const obsidian = Color(0xFF0C0E1C);
  static const card = Color(0xFF14172B);
  static const white = Color(0xFFFFFFFF);
}

/// Semantic colours for one brightness.
@immutable
class KatColors extends ThemeExtension<KatColors> {
  const KatColors({
    required this.canvas,
    required this.sheet,
    required this.sheetAlt,
    required this.line,
    required this.ink,
    required this.inkMuted,
    required this.primary,
    required this.onPrimary,
    required this.primarySoft,
    required this.badge,
    required this.reward,
    required this.kat,
    required this.katLine,
    required this.onKat,
    required this.onKatMuted,
    required this.danger,
    required this.success,
    required this.stage,
  });

  /// Page background.
  final Color canvas;

  /// Cards, sheets, panels.
  final Color sheet;

  /// Inputs, chips, icon wells.
  final Color sheetAlt;

  /// Hairlines and micro-borders.
  final Color line;
  final Color ink;
  final Color inkMuted;

  /// Indigo: the one primary action per screen.
  final Color primary;
  final Color onPrimary;

  /// Tinted fill for the navigation pill and selected tools.
  final Color primarySoft;

  /// Fuchsia: badges and unread dots only.
  final Color badge;

  /// Sun: rewards only.
  final Color reward;

  /// The « kat » voucher surface.
  final Color kat;
  final Color katLine;
  final Color onKat;
  final Color onKatMuted;
  final Color danger;
  final Color success;

  /// Backdrop behind photos in the editors.
  final Color stage;

  static const light = KatColors(
    canvas: KatPalette.paper,
    sheet: KatPalette.white,
    sheetAlt: Color(0xFFEFEEF6),
    line: KatPalette.hairline,
    ink: Color(0xFF12142B),
    inkMuted: Color(0xFF5B5E78),
    primary: KatPalette.indigo,
    onPrimary: KatPalette.white,
    primarySoft: Color(0xFFE6E4FB),
    badge: KatPalette.fuchsia,
    reward: KatPalette.sun,
    kat: KatPalette.kat,
    katLine: KatPalette.kat,
    onKat: KatPalette.white,
    onKatMuted: Color(0xFFA9ADCF),
    danger: Color(0xFFC62842),
    success: Color(0xFF1E7A4F),
    stage: Color(0xFFE7E6EF),
  );

  static const dark = KatColors(
    canvas: KatPalette.obsidian,
    sheet: KatPalette.card,
    sheetAlt: Color(0xFF1C2038),
    line: Color(0xFF262A47),
    ink: Color(0xFFF1F1F7),
    inkMuted: Color(0xFFA3A7C4),
    primary: KatPalette.indigo,
    onPrimary: KatPalette.white,
    primarySoft: Color(0xFF2A2763),
    badge: KatPalette.fuchsia,
    reward: KatPalette.sun,
    kat: KatPalette.kat,
    katLine: Color(0xFF2B3160),
    onKat: KatPalette.white,
    onKatMuted: Color(0xFFA9ADCF),
    danger: Color(0xFFFF6B81),
    success: Color(0xFF4CC38A),
    stage: Color(0xFF080A15),
  );

  static KatColors of(BuildContext context) => Theme.of(context).extension<KatColors>()!;

  @override
  KatColors copyWith({Color? canvas, Color? sheet}) => KatColors(
        canvas: canvas ?? this.canvas,
        sheet: sheet ?? this.sheet,
        sheetAlt: sheetAlt,
        line: line,
        ink: ink,
        inkMuted: inkMuted,
        primary: primary,
        onPrimary: onPrimary,
        primarySoft: primarySoft,
        badge: badge,
        reward: reward,
        kat: kat,
        katLine: katLine,
        onKat: onKat,
        onKatMuted: onKatMuted,
        danger: danger,
        success: success,
        stage: stage,
      );

  @override
  KatColors lerp(KatColors? other, double t) {
    if (other == null) return this;
    Color l(Color a, Color b) => Color.lerp(a, b, t)!;
    return KatColors(
      canvas: l(canvas, other.canvas),
      sheet: l(sheet, other.sheet),
      sheetAlt: l(sheetAlt, other.sheetAlt),
      line: l(line, other.line),
      ink: l(ink, other.ink),
      inkMuted: l(inkMuted, other.inkMuted),
      primary: l(primary, other.primary),
      onPrimary: l(onPrimary, other.onPrimary),
      primarySoft: l(primarySoft, other.primarySoft),
      badge: l(badge, other.badge),
      reward: l(reward, other.reward),
      kat: l(kat, other.kat),
      katLine: l(katLine, other.katLine),
      onKat: l(onKat, other.onKat),
      onKatMuted: l(onKatMuted, other.onKatMuted),
      danger: l(danger, other.danger),
      success: l(success, other.success),
      stage: l(stage, other.stage),
    );
  }
}

/// Type scale: 12 / 14 / 16 / 20 / 24 / 36 / 48 only.
abstract final class KatType {
  static const family = 'Bricolage';
  static const mono = 'JetBrainsMono';
  static const scale = <double>[12, 14, 16, 20, 24, 36, 48];

  /// Bricolage Grotesque with explicit variable axes.
  /// [condensed] uses the 75% width for money, counts and titles.
  static TextStyle ui(
    double size, {
    FontWeight weight = FontWeight.w400,
    bool condensed = false,
    bool tabular = false,
    Color? color,
    double? height,
  }) {
    assert(scale.contains(size), 'Font size $size is outside the Kat scale');
    return TextStyle(
      fontFamily: family,
      fontSize: size,
      fontWeight: weight,
      height: height ?? (size >= 36 ? 1.05 : size >= 20 ? 1.2 : 1.4),
      color: color,
      letterSpacing: size >= 24 ? -0.4 : 0,
      fontVariations: [
        FontVariation('wght', weight.value.toDouble()),
        FontVariation('wdth', condensed ? 75 : 100),
        FontVariation('opsz', size.clamp(12, 96).toDouble()),
      ],
      fontFeatures: tabular ? const [FontFeature.tabularFigures()] : null,
    );
  }

  /// JetBrains Mono: file names, sizes, hex codes and IDs only.
  static TextStyle code(double size, {Color? color, FontWeight weight = FontWeight.w500}) {
    assert(scale.contains(size));
    return TextStyle(
      fontFamily: mono,
      fontSize: size,
      color: color,
      height: 1.4,
      fontWeight: weight,
      fontVariations: [FontVariation('wght', weight.value.toDouble())],
      fontFeatures: const [FontFeature.tabularFigures()],
    );
  }
}

/// Spacing (multiples of 4) and radii.
abstract final class KatSpace {
  static const s4 = 4.0;
  static const s8 = 8.0;
  static const s12 = 12.0;
  static const s16 = 16.0;
  static const s20 = 20.0;
  static const s24 = 24.0;
  static const s32 = 32.0;
  static const gutter = 16.0;
  static const minTap = 44.0;
  static const navHeight = 80.0;
}

abstract final class KatRadius {
  static const tile = 12.0;
  static const button = 16.0;
  static const sheet = 20.0;
  static const kat = 24.0;
}

abstract final class KatMotion {
  static const press = Duration(milliseconds: 320);
  static const paint = Duration(milliseconds: 200);
  static const settle = Duration(milliseconds: 520);
  static const stagger = Duration(milliseconds: 40);
  static const curve = Cubic(0.16, 1, 0.3, 1);
}
