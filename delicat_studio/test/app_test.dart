import 'package:delicat_studio/core/settings.dart';
import 'package:delicat_studio/main.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await settings.load();
  });

  testWidgets('Home shows the kat hero, tools and navigation', (tester) async {
    tester.view.physicalSize = const Size(412 * 3, 915 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const DelicatStudioApp());
    await tester.pumpAndSettle();

    expect(find.text('Que crée-t-on aujourd’hui ?'), findsOneWidget);
    expect(find.text('Monter une vidéo'), findsOneWidget);
    expect(find.text('Détourer'), findsOneWidget);
    expect(find.text('Modèles promo'), findsOneWidget);
    expect(find.text('Diamants Free Fire'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Recommandé pour toi'), 300, scrollable: find.byType(Scrollable).first);
    expect(find.text('Recommandé pour toi'), findsOneWidget);
    expect(find.text('Monte une vidéo pour ta boutique'), findsOneWidget);
    expect(find.text('Studio'), findsWidgets);
    expect(find.text('Créations'), findsOneWidget);
  });

  testWidgets('Tabs switch to Créations and Réglages', (tester) async {
    tester.view.physicalSize = const Size(412 * 3, 915 * 3);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(const DelicatStudioApp());
    await tester.pumpAndSettle();

    await tester.tap(find.text('Créations'));
    await tester.pumpAndSettle();
    expect(find.text('Rien ici pour l’instant'), findsOneWidget);

    await tester.tap(find.text('Réglages'));
    await tester.pumpAndSettle();
    expect(find.text('Mode économie'), findsOneWidget);

    await tester.tap(find.text('Clair'));
    await tester.pumpAndSettle();
    expect(settings.themeMode, ThemeMode.light);
  });

  testWidgets('Every tap target in the navigation is at least 44px', (tester) async {
    await tester.pumpWidget(const DelicatStudioApp());
    await tester.pumpAndSettle();
    await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
  });
}
