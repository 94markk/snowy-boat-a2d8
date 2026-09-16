import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'ui/theme/theme.dart';
import 'ui/home/home_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // A widget that throws while building normally replaces the whole screen
  // with a red box in debug, and in release leaves a blank one — either way
  // the app looks like it has died with nothing to say about why. This keeps
  // the failure on screen in a form that can be read out.
  ErrorWidget.builder = (details) => _FailureCard(details: details);

  // Errors raised off the widget tree — a timer, a stream, a platform channel
  // answering late — have no handler by default and end the isolate. Swallowing
  // them here is not ideal, but an editor that survives a failed frame extract
  // is worth more than one that exits.
  PlatformDispatcher.instance.onError = (error, stack) {
    debugPrint('Uncaught: $error');
    return true;
  };

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


/// Shown in place of a widget that failed to build.
///
/// Deliberately plain and readable: the point is that someone can say what it
/// said, which a blank screen does not allow.
class _FailureCard extends StatelessWidget {
  const _FailureCard({required this.details});

  final FlutterErrorDetails details;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.black,
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Something went wrong here',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 8),
            Text(
              '${details.exception}',
              maxLines: 6,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: Color(0xFF9B9BA6), fontSize: 12),
            ),
          ],
        ),
      ),
    );
  }
}
