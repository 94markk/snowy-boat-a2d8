import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';

import 'tokens.dart';

/// Page transitions that respect low-power / reduced-motion.
class _InstantTransitions extends PageTransitionsBuilder {
  const _InstantTransitions();
  @override
  Widget buildTransitions<T>(PageRoute<T> route, BuildContext context, Animation<double> animation,
          Animation<double> secondaryAnimation, Widget child) =>
      child;
}

ThemeData buildTheme(Brightness brightness, {bool reduceMotion = false}) {
  final k = brightness == Brightness.dark ? KatColors.dark : KatColors.light;
  final scheme = ColorScheme(
    brightness: brightness,
    primary: k.primary,
    onPrimary: k.onPrimary,
    primaryContainer: k.primarySoft,
    onPrimaryContainer: k.ink,
    secondary: k.ink,
    onSecondary: k.canvas,
    tertiary: k.badge,
    onTertiary: KatPalette.white,
    error: k.danger,
    onError: KatPalette.white,
    surface: k.sheet,
    onSurface: k.ink,
    onSurfaceVariant: k.inkMuted,
    surfaceContainerLowest: k.canvas,
    surfaceContainerLow: k.sheet,
    surfaceContainer: k.sheet,
    surfaceContainerHigh: k.sheetAlt,
    surfaceContainerHighest: k.sheetAlt,
    outline: k.line,
    outlineVariant: k.line,
    shadow: const Color(0x00000000),
    scrim: const Color(0x99000000),
    surfaceTint: const Color(0x00000000),
    inverseSurface: k.kat,
    onInverseSurface: k.onKat,
  );

  TextStyle t(double s, [FontWeight w = FontWeight.w400, bool condensed = false]) =>
      KatType.ui(s, weight: w, condensed: condensed, color: k.ink);

  final textTheme = TextTheme(
    displayLarge: t(48, FontWeight.w700, true),
    displayMedium: t(36, FontWeight.w700, true),
    displaySmall: t(36, FontWeight.w600, true),
    headlineLarge: t(36, FontWeight.w700, true),
    headlineMedium: t(24, FontWeight.w700, true),
    headlineSmall: t(24, FontWeight.w600),
    titleLarge: t(20, FontWeight.w600),
    titleMedium: t(16, FontWeight.w600),
    titleSmall: t(14, FontWeight.w600),
    bodyLarge: t(16),
    bodyMedium: t(14),
    bodySmall: KatType.ui(12, color: k.inkMuted),
    labelLarge: t(16, FontWeight.w600),
    labelMedium: t(14, FontWeight.w500),
    labelSmall: t(12, FontWeight.w500),
  );

  final noTransitions = PageTransitionsTheme(builders: {
    for (final p in TargetPlatform.values) p: const _InstantTransitions(),
  });

  return ThemeData(
    useMaterial3: true,
    brightness: brightness,
    colorScheme: scheme,
    scaffoldBackgroundColor: k.canvas,
    canvasColor: k.canvas,
    fontFamily: KatType.family,
    textTheme: textTheme,
    extensions: [k],
    splashFactory: NoSplash.splashFactory,
    highlightColor: const Color(0x00000000),
    splashColor: const Color(0x00000000),
    hoverColor: k.sheetAlt,
    dividerColor: k.line,
    pageTransitionsTheme: reduceMotion ? noTransitions : null,
    appBarTheme: AppBarTheme(
      backgroundColor: k.canvas,
      foregroundColor: k.ink,
      elevation: 0,
      scrolledUnderElevation: 0,
      surfaceTintColor: const Color(0x00000000),
      centerTitle: false,
      titleTextStyle: t(20, FontWeight.w600),
      systemOverlayStyle: SystemUiOverlayStyle(
        statusBarColor: const Color(0x00000000),
        statusBarIconBrightness: brightness == Brightness.dark ? Brightness.light : Brightness.dark,
        statusBarBrightness: brightness,
        systemNavigationBarColor: k.sheet,
        systemNavigationBarIconBrightness: brightness == Brightness.dark ? Brightness.light : Brightness.dark,
      ),
    ),
    navigationBarTheme: NavigationBarThemeData(
      height: KatSpace.navHeight,
      backgroundColor: k.sheet,
      surfaceTintColor: const Color(0x00000000),
      shadowColor: const Color(0x00000000),
      elevation: 0,
      indicatorColor: k.primarySoft,
      indicatorShape: const StadiumBorder(),
      labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
      iconTheme: WidgetStateProperty.resolveWith(
        (s) => IconThemeData(size: 24, color: s.contains(WidgetState.selected) ? k.ink : k.inkMuted),
      ),
      labelTextStyle: WidgetStateProperty.resolveWith(
        (s) => KatType.ui(12,
            weight: s.contains(WidgetState.selected) ? FontWeight.w600 : FontWeight.w500,
            color: s.contains(WidgetState.selected) ? k.ink : k.inkMuted),
      ),
    ),
    sliderTheme: SliderThemeData(
      activeTrackColor: k.ink,
      inactiveTrackColor: k.line,
      thumbColor: k.ink,
      overlayColor: const Color(0x00000000),
      trackHeight: 4,
      showValueIndicator: ShowValueIndicator.never,
    ),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? k.onPrimary : k.inkMuted),
      trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? k.primary : k.sheetAlt),
      trackOutlineColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? k.primary : k.line),
    ),
    bottomSheetTheme: BottomSheetThemeData(
      backgroundColor: k.sheet,
      surfaceTintColor: const Color(0x00000000),
      modalBackgroundColor: k.sheet,
      elevation: 0,
      modalElevation: 0,
      showDragHandle: true,
      dragHandleColor: k.line,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(KatRadius.sheet))),
    ),
    dialogTheme: DialogThemeData(
      backgroundColor: k.sheet,
      surfaceTintColor: const Color(0x00000000),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(KatRadius.sheet),
        side: BorderSide(color: k.line),
      ),
      titleTextStyle: t(20, FontWeight.w600),
      contentTextStyle: KatType.ui(16, color: k.inkMuted),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: k.kat,
      contentTextStyle: KatType.ui(14, color: k.onKat),
      elevation: 0,
      insetPadding: const EdgeInsets.fromLTRB(16, 0, 16, 96),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(KatRadius.button)),
    ),
    progressIndicatorTheme: ProgressIndicatorThemeData(color: k.ink, linearTrackColor: k.line, circularTrackColor: k.line),
    textSelectionTheme: TextSelectionThemeData(cursorColor: k.primary, selectionColor: k.primarySoft, selectionHandleColor: k.primary),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: k.sheetAlt,
      hintStyle: KatType.ui(16, color: k.inkMuted),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(KatRadius.button), borderSide: BorderSide(color: k.line)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(KatRadius.button), borderSide: BorderSide(color: k.line)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(KatRadius.button), borderSide: BorderSide(color: k.primary, width: 2)),
    ),
    tooltipTheme: TooltipThemeData(
      decoration: BoxDecoration(color: k.kat, borderRadius: BorderRadius.circular(8)),
      textStyle: KatType.ui(12, color: k.onKat),
    ),
    iconTheme: IconThemeData(color: k.ink, size: 24),
  );
}
