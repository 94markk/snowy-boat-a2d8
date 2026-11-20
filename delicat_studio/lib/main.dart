import 'package:flutter/services.dart';
import 'package:material_ui/material_ui.dart';

import 'core/creations.dart';
import 'core/settings.dart';
import 'screens/shell.dart';
import 'theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  SystemChrome.setEnabledSystemUIMode(SystemUiMode.edgeToEdge);
  await settings.load();
  await creations.load();
  runApp(const DelicatStudioApp());
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
