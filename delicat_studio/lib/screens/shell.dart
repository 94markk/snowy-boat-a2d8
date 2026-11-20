import 'package:material_ui/material_ui.dart';

import '../theme/tokens.dart';
import 'creations_screen.dart';
import 'home_screen.dart';
import 'settings_screen.dart';

/// Docked 80px navigation with the pill indicator. Content never sits under it.
class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => AppShellState();

  static AppShellState? of(BuildContext context) => context.findAncestorStateOfType<AppShellState>();
}

class AppShellState extends State<AppShell> {
  int _index = 0;

  void goTo(int i) => setState(() => _index = i);

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Scaffold(
      body: IndexedStack(
        index: _index,
        children: const [HomeScreen(), CreationsScreen(), SettingsScreen()],
      ),
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: k.line))),
        child: NavigationBar(
          selectedIndex: _index,
          onDestinationSelected: goTo,
          destinations: const [
            NavigationDestination(icon: Icon(Icons.auto_awesome_outlined), selectedIcon: Icon(Icons.auto_awesome_rounded), label: 'Studio'),
            NavigationDestination(icon: Icon(Icons.photo_library_outlined), selectedIcon: Icon(Icons.photo_library_rounded), label: 'Créations'),
            NavigationDestination(icon: Icon(Icons.tune_rounded), selectedIcon: Icon(Icons.tune_rounded), label: 'Réglages'),
          ],
        ),
      ),
    );
  }
}
