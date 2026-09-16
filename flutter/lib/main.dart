import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'ui/theme/theme.dart';
import 'ui/home/home_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Portrait, to match the manifest. The manifest alone is not enough: a
  // launcher or a split-screen transition can hand the activity back with a
  // sensor-derived orientation, and an editor that rotates mid-drag loses both
  // the gesture and the layout the user was aiming at.
  await SystemChrome.setPreferredOrientations([
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: Colors.black,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );

  runApp(const DelicatApp());
}

class DelicatApp extends StatelessWidget {
  const DelicatApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Delicat Studio',
      debugShowCheckedModeBanner: false,
      theme: delicatTheme(),
      home: const HomeScreen(),
    );
  }
}
