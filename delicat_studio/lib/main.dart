import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';

import 'core/brand.dart';
import 'core/crash_log.dart';
import 'core/creations.dart';
import 'core/drafts.dart';
import 'core/settings.dart';
import 'core/system.dart';
import 'screens/shell.dart';
import 'theme/app_theme.dart';
import 'theme/tokens.dart';

Future<void> main() async {
  await runZonedGuarded(() async {
    WidgetsFlutterBinding.ensureInitialized();
    await CrashLog.init();
    FlutterError.onError = (details) {
      FlutterError.presentError(details);
      CrashLog.record(details.exception, details.stack, context: details.context?.toDescription() ?? 'flutter');
    };
    PlatformDispatcher.instance.onError = (error, stack) {
      CrashLog.record(error, stack, context: 'platform');
      return true;
    };
    if (kReleaseMode) ErrorWidget.builder = (details) => const _BrokenPart();
    SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
    await settings.load();
    await Future.wait([creations.load(), brand.load(), drafts.load(), SystemBridge.loadDevice()]);
    settings.applyDeviceDefaults(SystemBridge.device);
    runApp(const DelicatStudioApp());
  }, (error, stack) => CrashLog.record(error, stack, context: 'zone'));
}

class DelicatStudioApp extends StatelessWidget {
  const DelicatStudioApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: settings,
      builder: (context, _) {
        final calm = settings.lowPower;
        return MaterialApp(
          title: 'Delicat Studio',
          debugShowCheckedModeBanner: false,
          themeMode: settings.themeMode,
          theme: buildTheme(Brightness.light, reduceMotion: calm),
          darkTheme: buildTheme(Brightness.dark, reduceMotion: calm),
          locale: const Locale('fr'),
          home: const AppShell(),
        );
      },
    );
  }
}

/// Shown instead of a broken widget in release builds, so one bad tile never blanks a screen.
class _BrokenPart extends StatelessWidget {
  const _BrokenPart();

  @override
  Widget build(BuildContext context) {
    final k = Theme.of(context).extension<KatColors>();
    return Container(
      padding: const EdgeInsets.all(KatSpace.s12),
      alignment: Alignment.center,
      child: Text(
        'Cette partie n’a pas pu s’afficher.',
        textAlign: TextAlign.center,
        style: KatType.ui(14, color: k?.inkMuted ?? const Color(0xFF8A8FA8)),
      ),
    );
  }
}
