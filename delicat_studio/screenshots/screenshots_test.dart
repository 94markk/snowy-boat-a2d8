// ignore_for_file: invalid_use_of_visible_for_testing_member
// Renders store-style screenshots of the main screens with the real fonts.
// Run:  flutter test screenshots --update-goldens   (PNG files land in screenshots/goldens/)
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:delicat_studio/core/image_io.dart';
import 'package:delicat_studio/core/settings.dart';
import 'package:delicat_studio/features/collage/collage_screen.dart';
import 'package:delicat_studio/features/cutout/cutout_screen.dart';
import 'package:delicat_studio/features/editor/editor_screen.dart';
import 'package:delicat_studio/features/video/video_editor_screen.dart';
import 'package:delicat_studio/features/video/video_model.dart';
import 'package:delicat_studio/features/video/video_timeline.dart';
import 'package:delicat_studio/main.dart';
import 'package:delicat_studio/theme/app_theme.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

Future<void> _loadFonts() async {
  const fonts = {
    'Bricolage': 'assets/fonts/BricolageGrotesque.ttf',
    'JetBrainsMono': 'assets/fonts/JetBrainsMono.ttf',
    'Playfair': 'assets/fonts/PlayfairDisplay.ttf',
    'Pacifico': 'assets/fonts/Pacifico.ttf',
    'BebasNeue': 'assets/fonts/BebasNeue.ttf',
    'Caveat': 'assets/fonts/Caveat.ttf',
  };
  for (final e in fonts.entries) {
    await (FontLoader(e.key)..addFont(rootBundle.load(e.value))).load();
  }
  final root = Platform.environment['FLUTTER_ROOT'];
  final icons = File('$root/bin/cache/artifacts/material_fonts/MaterialIcons-Regular.otf');
  if (icons.existsSync()) {
    final bytes = icons.readAsBytesSync();
    await (FontLoader('MaterialIcons')..addFont(Future.value(ByteData.sublistView(bytes)))).load();
  }
}

/// A synthetic portrait: warm backdrop, a figure in the middle.
Future<(ui.Image, Float32List)> _portrait(int w, int h, {int seed = 0}) async {
  final palettes = [
    [const Color(0xFFF6B48F), const Color(0xFF7FB7BE), const Color(0xFF2E3A59)],
    [const Color(0xFFFFD27D), const Color(0xFF59C3C3), const Color(0xFF4B3FE0)],
    [const Color(0xFFB8E0D2), const Color(0xFFEAC4D5), const Color(0xFF7A3E65)],
    [const Color(0xFFFFE5B4), const Color(0xFF95B8D1), const Color(0xFF0F4C5C)],
  ];
  final p = palettes[seed % palettes.length];
  final rec = ui.PictureRecorder();
  final c = Canvas(rec);
  final size = Size(w.toDouble(), h.toDouble());
  c.drawRect(Offset.zero & size, Paint()..shader = ui.Gradient.linear(Offset.zero, Offset(0, size.height), [p[0], p[1]]));
  c.drawCircle(Offset(w * 0.78, h * 0.18), w * 0.12, Paint()..color = const Color(0xFFFFF4D6));
  final rnd = math.Random(seed + 3);
  for (var i = 0; i < 9; i++) {
    c.drawCircle(Offset(rnd.nextDouble() * w, h * 0.7 + rnd.nextDouble() * h * 0.3), w * (0.08 + rnd.nextDouble() * 0.12),
        Paint()..color = p[1].withValues(alpha: 0.55));
  }
  final body = Path()
    ..addOval(Rect.fromCircle(center: Offset(w * 0.5, h * 0.36), radius: w * 0.14))
    ..addRRect(RRect.fromRectAndRadius(Rect.fromLTWH(w * 0.27, h * 0.5, w * 0.46, h * 0.52), Radius.circular(w * 0.2)));
  c.drawPath(body, Paint()..color = p[2]);
  c.drawCircle(Offset(w * 0.5, h * 0.36), w * 0.11, Paint()..color = const Color(0xFFC98D6B));
  final img = await rec.endRecording().toImage(w, h);
  final conf = Float32List(w * h);
  for (var y = 0; y < h; y++) {
    for (var x = 0; x < w; x++) {
      final inHead = (Offset(x.toDouble(), y.toDouble()) - Offset(w * 0.5, h * 0.36)).distance <= w * 0.14;
      final inBody = x >= w * 0.27 && x <= w * 0.73 && y >= h * 0.5;
      conf[y * w + x] = inHead || inBody ? 0.97 : 0.02;
    }
  }
  return (img, conf);
}

Future<void> _shot(WidgetTester tester, Widget app, String name, {Future<void> Function()? after, int cycles = 6}) async {
  tester.view.physicalSize = const Size(1080, 2340);
  tester.view.devicePixelRatio = 2.625;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(app);
  for (var i = 0; i < cycles; i++) {
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 150)));
    await tester.pump(const Duration(milliseconds: 600));
  }
  if (after != null) await after();
  await expectLater(find.byType(MaterialApp), matchesGoldenFile('goldens/$name.png'));
}

Widget _wrap(Widget home, {Brightness b = Brightness.dark}) =>
    MaterialApp(debugShowCheckedModeBanner: false, theme: buildTheme(b), home: home);

void main() {
  setUpAll(_loadFonts);
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await settings.load();
  });

  testWidgets('home dark', (t) => _shot(t, const DelicatStudioApp(), '01_home_dark'));

  testWidgets('home light', (t) async {
    settings.setThemeMode(ThemeMode.light);
    await _shot(t, const DelicatStudioApp(), '02_home_light');
  });

  testWidgets('cutout portrait blur', (t) async {
    final (img, conf) = (await t.runAsync(() => _portrait(720, 960)))!;
    await _shot(t, _wrap(CutoutScreen(photo: Photo('', img), preset: CutoutPreset.portrait, debugConfidence: conf)), '03_cutout_portrait');
  });

  testWidgets('cutout sticker', (t) async {
    final (img, conf) = (await t.runAsync(() => _portrait(720, 960, seed: 1)))!;
    await _shot(t, _wrap(CutoutScreen(photo: Photo('', img), preset: CutoutPreset.sticker, debugConfidence: conf)), '04_cutout_sticker');
  });

  testWidgets('collage', (t) async {
    final photos = <Photo>[];
    for (var i = 0; i < 4; i++) {
      final (img, _) = (await t.runAsync(() => _portrait(600, 800, seed: i)))!;
      photos.add(Photo('', img));
    }
    await _shot(t, _wrap(CollageScreen(photos: photos)), '05_collage');
  });

  testWidgets('editor styles', (t) async {
    final (img, _) = (await t.runAsync(() => _portrait(900, 1200, seed: 3)))!;
    await _shot(t, _wrap(EditorScreen(photo: Photo('', img), initialTool: EditorTool.styles)), '06_editor_styles', after: () async {
      await t.tap(find.text('Velours'));
      for (var i = 0; i < 3; i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
        await t.pump(const Duration(milliseconds: 400));
      }
    });
  });

  testWidgets('video editor', (t) async {
    final paths = (await t.runAsync(() async {
      final dir = await Directory.systemTemp.createTemp('ds_shots');
      final out = <String>[];
      for (var i = 0; i < 3; i++) {
        final (img, _) = await _portrait(720, 1280, seed: i);
        final png = await img.toByteData(format: ui.ImageByteFormat.png);
        final f = File('${dir.path}/clip_$i.png');
        await f.writeAsBytes(png!.buffer.asUint8List());
        out.add(f.path);
      }
      return out;
    }))!;
    await _shot(t, _wrap(VideoEditorScreen(paths: paths, initialRatio: VRatio.story)), '08_video_editor', cycles: 40, after: () async {
      Future<void> settle() async {
        for (var i = 0; i < 4; i++) {
          await t.pump(const Duration(milliseconds: 300));
        }
      }

      await t.tap(find.text('Texte'));
      await settle();
      await t.enterText(find.byType(TextField), 'Promo -20 %');
      await t.tap(find.text('Ajouter le texte'));
      await settle();
      // Select the first clip on the timeline and show the transition marker.
      final box = t.getRect(find.byType(VideoTimeline));
      await t.tapAt(Offset(box.center.dx + 40, box.top + 60));
      for (var i = 0; i < 3; i++) {
        await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
        await t.pump(const Duration(milliseconds: 400));
      }
    });
  });

  testWidgets('settings light', (t) async {
    settings.setThemeMode(ThemeMode.light);
    await _shot(t, const DelicatStudioApp(), '07_settings_light', after: () async {
      await t.tap(find.text('Réglages'));
      await t.pump(const Duration(milliseconds: 600));
    });
  });
}
