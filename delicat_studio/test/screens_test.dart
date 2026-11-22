import 'package:delicat_studio/core/settings.dart';
import 'package:delicat_studio/features/templates/template_screens.dart';
import 'package:delicat_studio/features/templates/templates.dart';
import 'package:delicat_studio/screens/brand_screen.dart';
import 'package:delicat_studio/screens/drafts_screen.dart';
import 'package:delicat_studio/screens/settings_screen.dart';
import 'package:delicat_studio/theme/app_theme.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

Future<void> _show(WidgetTester tester, Widget child, {Size size = const Size(360, 780)}) async {
  tester.view.physicalSize = size * 3;
  tester.view.devicePixelRatio = 3;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(theme: buildTheme(Brightness.dark), home: child));
  await tester.pump(const Duration(milliseconds: 400));
}

void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await settings.load();
  });

  testWidgets('Every template form renders on a small phone', (tester) async {
    for (final t in kPromoTemplates) {
      await _show(tester, TemplateScreen(template: t));
      expect(find.text('Affiche'), findsOneWidget, reason: t.id);
      expect(find.text('Vidéo'), findsOneWidget, reason: t.id);
      expect(tester.takeException(), isNull, reason: t.id);
    }
  });

  testWidgets('Switching the template language swaps the quick choices', (tester) async {
    await _show(tester, TemplateScreen(template: kPromoTemplates.first));
    expect(find.text('110 diamants'), findsWidgets);
    await tester.tap(find.text('Kreyòl'));
    await tester.pump();
    expect(find.text('110 dyaman'), findsWidgets);
  });

  testWidgets('A wrong price is flagged and blocks the buttons', (tester) async {
    await _show(tester, TemplateScreen(template: kPromoTemplates.first));
    await tester.scrollUntilVisible(find.text('Prix'), 200, scrollable: find.byType(Scrollable).first);
    await tester.enterText(find.byType(TextField).at(1), 'abc');
    await tester.pump();
    expect(find.text('Écris un montant, par exemple 1500'), findsOneWidget);
  });

  testWidgets('Brand screen shows logo, contact fields and colours', (tester) async {
    await _show(tester, const BrandScreen());
    expect(find.text('Ma marque'), findsOneWidget);
    expect(find.text('Choisir le logo'), findsOneWidget);
    expect(find.text('Nom de la boutique'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('Drafts screen explains itself when empty', (tester) async {
    await _show(tester, const DraftsScreen());
    expect(find.textContaining('Rien en cours'), findsOneWidget);
  });

  testWidgets('Settings offer brand, assistance and privacy', (tester) async {
    await _show(tester, const Scaffold(body: SettingsScreen()));
    expect(find.text('Ma marque'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Signaler un problème'), 300, scrollable: find.byType(Scrollable).first);
    expect(find.text('Signaler un problème'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Politique de confidentialité'), 300, scrollable: find.byType(Scrollable).first);
    expect(find.text('Delicat Studio 1.2.0'), findsOneWidget);
  });

  testWidgets('Template strip fits its cards', (tester) async {
    await _show(tester, const Scaffold(body: TemplateStrip()));
    expect(find.text('Diamants Free Fire'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });
}
