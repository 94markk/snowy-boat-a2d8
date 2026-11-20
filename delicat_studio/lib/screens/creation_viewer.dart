import 'dart:io';

import 'package:material_ui/material_ui.dart';

import '../core/creations.dart';
import '../core/image_io.dart';
import '../features/editor/editor_screen.dart';
import '../features/launch.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/kat_button.dart';
import 'creations_screen.dart';

class CreationViewer extends StatelessWidget {
  const CreationViewer({super.key, required this.creation});
  final Creation creation;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final c = creation;
    final d = c.created;
    String two(int v) => v.toString().padLeft(2, '0');
    final date = '${two(d.day)}/${two(d.month)}/${d.year} à ${two(d.hour)}h${two(d.minute)}';
    return Scaffold(
      body: Column(
        children: [
          SafeArea(
            bottom: false,
            child: SizedBox(
              height: 60,
              child: Row(
                children: [
                  const SizedBox(width: KatSpace.s4),
                  KatIconButton(icon: Icons.arrow_back_rounded, tooltip: 'Retour', onPressed: () => Navigator.pop(context)),
                  Expanded(child: Text(c.kind.label, style: KatType.ui(16, weight: FontWeight.w600, color: k.ink))),
                  KatIconButton(
                    icon: Icons.download_rounded,
                    tooltip: 'Ajouter à la galerie',
                    onPressed: () async {
                      try {
                        await ImageIO.saveToGallery(await File(c.file).readAsBytes(), c.fileName.split('.').first);
                        if (context.mounted) showKatSnack(context, 'Ajoutée à la galerie, album Delicat Studio.');
                      } catch (_) {
                        if (context.mounted) showKatSnack(context, 'La galerie a refusé l’accès. Autorise-le dans les réglages Android.');
                      }
                    },
                  ),
                  KatIconButton(
                    icon: Icons.delete_outline_rounded,
                    tooltip: 'Supprimer',
                    onPressed: () async {
                      if (await deleteCreation(context, c) && context.mounted) Navigator.pop(context);
                    },
                  ),
                  const SizedBox(width: KatSpace.s8),
                ],
              ),
            ),
          ),
          Expanded(
            child: Container(
              color: k.stage,
              width: double.infinity,
              child: InteractiveViewer(
                maxScale: 6,
                child: Center(
                  child: CustomPaint(
                    painter: CheckerPainter(k.stage, k.sheetAlt),
                    child: Image.file(File(c.file), fit: BoxFit.contain),
                  ),
                ),
              ),
            ),
          ),
          Container(
            decoration: BoxDecoration(color: k.sheet, border: Border(top: BorderSide(color: k.line))),
            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, KatSpace.s16),
            child: SafeArea(
              top: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(c.fileName, style: KatType.code(14, color: k.ink)),
                  const SizedBox(height: KatSpace.s4),
                  Text('${c.width} × ${c.height} px  ${formatBytes(c.bytes)}', style: KatType.code(12, color: k.inkMuted)),
                  Text('Créée le $date', style: KatType.ui(14, color: k.inkMuted)),
                  const SizedBox(height: KatSpace.s16),
                  Row(
                    children: [
                      Expanded(
                        child: KatButton(
                          label: 'Partager',
                          icon: Icons.share_rounded,
                          expand: true,
                          onPressed: () => ImageIO.shareFile(c.file, text: 'Créé avec Delicat Studio'),
                        ),
                      ),
                      const SizedBox(width: KatSpace.s8),
                      Expanded(
                        child: KatButton(
                          label: 'Retoucher',
                          icon: Icons.tune_rounded,
                          kind: KatButtonKind.secondary,
                          expand: true,
                          onPressed: () async {
                            final nav = Navigator.of(context);
                            final photo = await withLoader(context, 'Ouverture…', () => ImageIO.load(c.file));
                            if (photo != null) await nav.push(MaterialPageRoute(builder: (_) => EditorScreen(photo: photo)));
                          },
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
