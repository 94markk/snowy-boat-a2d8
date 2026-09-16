import 'package:flutter/material.dart';

/// Editor palette. Dark and low-chroma so the media on the canvas, not the
/// chrome around it, is what the eye grades against.
///
/// The shell sits at true black rather than a dark grey. On the OLED panel of
/// most phones that makes the chrome disappear at the edge of the preview, so
/// the frame reads as the only lit thing on screen, and the black bars beside
/// a portrait clip stop looking like part of the interface.
class Shade {
  const Shade._();

  static const Color i900 = Color(0xFF000000);
  static const Color i800 = Color(0xFF0A0A0C);
  static const Color i700 = Color(0xFF131316);
  static const Color i600 = Color(0xFF1C1C20);
  static const Color i500 = Color(0xFF2A2A30);
  static const Color i400 = Color(0xFF3A3A42);
}

class Mist {
  const Mist._();

  static const Color m200 = Color(0xFFF5F5F7);
  static const Color m400 = Color(0xFF9B9BA6);
  static const Color m600 = Color(0xFF66666F);
}

/// One accent, used for anything live: the playhead, a selected clip, a slider
/// that is off its default. Keeping it to a single hue means a glance answers
/// "what have I changed" without reading any labels.
const Color aqua = Color(0xFF1FE0E8);
const Color aquaDim = Color(0xFF0E6F74);
const Color rose = Color(0xFFFF6B81);

ThemeData delicatTheme() {
  const scheme = ColorScheme.dark(
    primary: aqua,
    onPrimary: Shade.i900,
    primaryContainer: aquaDim,
    onPrimaryContainer: Mist.m200,
    secondary: Color(0xFFB08CFF),
    onSecondary: Shade.i900,
    surface: Shade.i800,
    onSurface: Mist.m200,
    surfaceContainerHighest: Shade.i600,
    onSurfaceVariant: Mist.m400,
    outline: Shade.i400,
    outlineVariant: Shade.i500,
    error: rose,
    onError: Shade.i900,
  );

  return ThemeData(
    useMaterial3: true,
    brightness: Brightness.dark,
    colorScheme: scheme,
    scaffoldBackgroundColor: Shade.i900,
    canvasColor: Shade.i900,
    splashFactory: InkSparkle.splashFactory,
    sliderTheme: const SliderThemeData(
      activeTrackColor: aqua,
      inactiveTrackColor: Shade.i500,
      thumbColor: aqua,
      overlayColor: Color(0x331FE0E8),
      trackHeight: 3,
    ),
    textTheme: const TextTheme(
      titleLarge: TextStyle(
          fontSize: 22, fontWeight: FontWeight.w600, letterSpacing: -0.2),
      titleMedium: TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
      bodyMedium: TextStyle(fontSize: 14),
      bodySmall: TextStyle(fontSize: 12),
      labelMedium: TextStyle(
          fontSize: 12, fontWeight: FontWeight.w500, letterSpacing: 0.2),
      labelSmall: TextStyle(
          fontSize: 10, fontWeight: FontWeight.w500, letterSpacing: 0.3),
    ),
  );
}
