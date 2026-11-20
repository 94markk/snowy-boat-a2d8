import 'package:material_ui/material_ui.dart';

import '../core/settings.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SafeArea(
      bottom: false,
      child: ListenableBuilder(
        listenable: settings,
        builder: (context, _) => ListView(
          padding: const EdgeInsets.only(bottom: KatSpace.s32),
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, 0),
              child: Text('Réglages', style: KatType.ui(36, weight: FontWeight.w700, condensed: true, color: k.ink)),
            ),
            const SectionHeader('Apparence'),
            KatChoices<ThemeMode>(
              items: const [ThemeMode.system, ThemeMode.light, ThemeMode.dark],
              value: settings.themeMode,
              icons: (m) => switch (m) {
                ThemeMode.system => Icons.brightness_auto_rounded,
                ThemeMode.light => Icons.light_mode_rounded,
                ThemeMode.dark => Icons.dark_mode_rounded,
              },
              label: (m) => switch (m) {
                ThemeMode.system => 'Système',
                ThemeMode.light => 'Clair',
                ThemeMode.dark => 'Sombre',
              },
              onChanged: settings.setThemeMode,
            ),
            _SwitchRow(
              title: 'Mode économie',
              subtitle: 'Coupe les animations pour les téléphones lents ou la batterie faible.',
              value: settings.lowPower,
              onChanged: settings.setLowPower,
            ),
            const SectionHeader('Import des photos'),
            for (final q in ImportQuality.values)
              _RadioRow(
                title: '${q.label}  ${q.maxSide} px',
                subtitle: q.hint,
                selected: settings.importQuality == q,
                onTap: () => settings.setImportQuality(q),
              ),
            const SectionHeader('Enregistrement'),
            KatChoices<ExportFormat>(
              items: ExportFormat.values,
              value: settings.format,
              label: (f) => f == ExportFormat.png ? 'PNG par défaut' : 'JPG par défaut',
              onChanged: settings.setFormat,
            ),
            SliderRow(
              label: 'Qualité JPG',
              value: settings.jpgQuality.toDouble(),
              min: 60,
              max: 100,
              divisions: 40,
              format: (v) => '${v.round()} %',
              onChanged: (v) => settings.setJpgQuality(v.round()),
            ),
            _SwitchRow(
              title: 'Ajouter à la galerie',
              subtitle: 'Chaque image enregistrée va aussi dans l’album Delicat Studio.',
              value: settings.saveToGallery,
              onChanged: settings.setSaveToGallery,
            ),
            const SectionHeader('Confidentialité'),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
              child: KatSheet(
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(Icons.lock_rounded, color: k.ink),
                    const SizedBox(width: KatSpace.s12),
                    Expanded(
                      child: Text(
                        'Le détourage se fait sur ton téléphone avec Google ML Kit. Tes photos ne sont jamais envoyées sur un serveur.',
                        style: KatType.ui(14, color: k.inkMuted),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: KatSpace.s24),
            Center(child: Text('Delicat Studio 1.0.0', style: KatType.code(12, color: k.inkMuted))),
          ],
        ),
      ),
    );
  }
}

class _SwitchRow extends StatelessWidget {
  const _SwitchRow({required this.title, required this.subtitle, required this.value, required this.onChanged});
  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return MergeSemantics(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s12, KatSpace.gutter, 0),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: KatType.ui(16, weight: FontWeight.w500, color: k.ink)),
                  const SizedBox(height: 2),
                  Text(subtitle, style: KatType.ui(14, color: k.inkMuted)),
                ],
              ),
            ),
            const SizedBox(width: KatSpace.s12),
            Switch(value: value, onChanged: onChanged),
          ],
        ),
      ),
    );
  }
}

class _RadioRow extends StatelessWidget {
  const _RadioRow({required this.title, required this.subtitle, required this.selected, required this.onTap});
  final String title;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Semantics(
      inMutuallyExclusiveGroup: true,
      checked: selected,
      button: true,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s8),
          child: Row(
            children: [
              AnimatedContainer(
                duration: KatMotion.paint,
                width: 24,
                height: 24,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  border: Border.all(color: selected ? k.primary : k.line, width: selected ? 7 : 2),
                ),
              ),
              const SizedBox(width: KatSpace.s16),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(title, style: KatType.ui(16, weight: FontWeight.w500, tabular: true, color: k.ink)),
                    Text(subtitle, style: KatType.ui(14, color: k.inkMuted)),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
