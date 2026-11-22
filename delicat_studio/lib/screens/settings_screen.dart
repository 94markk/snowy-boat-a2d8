import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';

import '../core/brand.dart';
import '../core/crash_log.dart';
import '../core/drafts.dart';
import '../core/image_io.dart';
import '../core/settings.dart';
import '../core/system.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/pressable.dart';
import 'brand_screen.dart';
import 'drafts_screen.dart';
import 'privacy_screen.dart';

/// Shares the device description and the local crash journal (only when the person asks).
Future<void> shareProblemReport(BuildContext context) async {
  try {
    final log = await CrashLog.read();
    final dir = await getTemporaryDirectory();
    final f = File('${dir.path}/rapport_delicat_studio.txt');
    await f.writeAsString(
      'Delicat Studio ${CrashLog.appVersion}\n'
      'Appareil : ${SystemBridge.device.describe()}\n'
      'Date : ${DateTime.now().toIso8601String()}\n\n'
      'Décris ce que tu faisais quand le problème est arrivé :\n\n\n'
      '--- Journal ---\n${log.isEmpty ? 'Aucune erreur enregistrée.' : log}',
      flush: true,
    );
    await ImageIO.shareFile(f.path, text: 'Rapport de problème Delicat Studio');
  } catch (_) {
    if (context.mounted) showKatSnack(context, 'Le rapport n’a pas pu être préparé. Réessaie.');
  }
}

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
            const SectionHeader('Ma marque'),
            ListenableBuilder(
              listenable: brand,
              builder: (context, _) => _LinkRow(
                icon: Icons.storefront_rounded,
                title: brand.kit.name,
                subtitle: 'Logo, couleurs, police, lien et WhatsApp',
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const BrandScreen())),
              ),
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
            const SectionHeader('Vidéo'),
            KatChoices<int>(
              items: const [720, 1080],
              value: settings.videoRes,
              label: (r) => r == 720 ? '720p, plus rapide' : '1080p, plus net',
              onChanged: settings.setVideoRes,
            ),
            if (SystemBridge.device.isBudget)
              Padding(
                padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, 0),
                child: Text('720p est conseillé sur ce téléphone.', style: KatType.ui(14, color: k.inkMuted)),
              ),
            const SectionHeader('Brouillons'),
            ListenableBuilder(
              listenable: drafts,
              builder: (context, _) => _LinkRow(
                icon: Icons.history_rounded,
                title: drafts.items.isEmpty ? 'Aucun brouillon' : '${drafts.items.length} ${drafts.items.length > 1 ? 'brouillons' : 'brouillon'}',
                subtitle: 'Tes montages, retouches et collages en cours',
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const DraftsScreen())),
              ),
            ),
            const SectionHeader('Assistance'),
            _LinkRow(
              icon: Icons.bug_report_rounded,
              title: 'Signaler un problème',
              subtitle: 'Prépare un rapport avec ton modèle de téléphone et le journal d’erreurs, que tu envoies toi-même.',
              onTap: () => shareProblemReport(context),
            ),
            FutureBuilder<int>(
              future: CrashLog.count(),
              builder: (context, snap) {
                final n = snap.data ?? 0;
                if (n == 0) return const SizedBox.shrink();
                return _LinkRow(
                  icon: Icons.delete_sweep_rounded,
                  title: 'Effacer le journal',
                  subtitle: n > 1 ? '$n erreurs enregistrées sur ce téléphone' : '1 erreur enregistrée sur ce téléphone',
                  onTap: () async {
                    await CrashLog.clear();
                    if (context.mounted) showKatSnack(context, 'Journal effacé.');
                    settings.refresh();
                  },
                );
              },
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
            const SizedBox(height: KatSpace.s8),
            _LinkRow(
              icon: Icons.privacy_tip_rounded,
              title: 'Politique de confidentialité',
              subtitle: 'Ce que l’appli garde, et ce qu’elle ne fait jamais',
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const PrivacyScreen())),
            ),
            const SizedBox(height: KatSpace.s24),
            Center(child: Text('Delicat Studio ${CrashLog.appVersion}', style: KatType.code(12, color: k.inkMuted))),
          ],
        ),
      ),
    );
  }
}

class _LinkRow extends StatelessWidget {
  const _LinkRow({required this.icon, required this.title, required this.subtitle, required this.onTap});
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Pressable(
      onTap: onTap,
      semanticLabel: title,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s8),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: k.sheetAlt, borderRadius: BorderRadius.circular(KatRadius.tile)),
              child: Icon(icon, size: 20, color: k.ink),
            ),
            const SizedBox(width: KatSpace.s12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: KatType.ui(16, weight: FontWeight.w500, color: k.ink)),
                  Text(subtitle, style: KatType.ui(14, color: k.inkMuted)),
                ],
              ),
            ),
            Icon(Icons.chevron_right_rounded, color: k.inkMuted),
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
